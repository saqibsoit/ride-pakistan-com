<?php

declare(strict_types=1);

use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Credit\AccountCredit;
use App\Actions\Referrals\AttachReferral;
use App\Actions\Referrals\EnsureReferralCode;
use App\Actions\Referrals\ReferralAbuseCheck;
use App\Actions\Referrals\ReviewReferral;
use App\Actions\Referrals\RewardReferral;
use App\Enums\AccountCreditReason;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\ReferralStatus;
use App\Exceptions\CreditRejected;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Referrals\Pages\ListReferrals;
use App\Filament\Resources\Referrals\ReferralResource;
use App\Mail\LoginCodeMail;
use App\Models\AccountCreditEntry;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Referral;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\ReferralRewardEarned;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

function referrer(array $attributes = []): User
{
    $user = User::factory()->create(['email' => 'amna.k@gmail.com', 'phone' => '+923001234567', ...$attributes]);
    app(EnsureReferralCode::class)->handle($user);

    return $user;
}

/** A booking for the customer that has started, paid through an order with the given status. */
function startedBooking(User $customer, OrderStatus $status = OrderStatus::Paid, int $amount = 1_000_000): Booking
{
    $order = Order::factory()->status($status)->create(['user_id' => $customer->id, 'amount_minor' => $amount]);
    $slot = Slot::factory()->at(CarbonImmutable::now()->subMinutes(random_int(30, 50_000)))->create();

    return Booking::factory()->create(['user_id' => $customer->id, 'order_id' => $order->id, 'slot_id' => $slot->id]);
}

function signUpThroughLink(TestCase $test, string $code, string $email): void
{
    config()->set('services.turnstile.site_key', null);
    config()->set('services.turnstile.secret_key', null);

    $test->get('/r/'.$code)->assertRedirect(route('home'));
    $test->post('/login', ['email' => $email]);
    $mail = Mail::sent(LoginCodeMail::class)->last();
    $test->post(route('login.verify.store'), ['code' => $mail->code]);
}

beforeEach(function (): void {
    Notification::fake();
    Mail::fake();
});

it('gives each customer one stable referral code and link', function (): void {
    $user = User::factory()->create();

    $code = app(EnsureReferralCode::class)->handle($user);

    expect($code)->toMatch('/^[A-Z0-9]{8}$/')
        ->and(app(EnsureReferralCode::class)->handle($user->fresh() ?? $user))->toBe($code)
        ->and(EnsureReferralCode::url($user))->toEndWith('/r/'.$code);
});

it('records a referral when a new account is created from the link', function (): void {
    $referrer = referrer();

    signUpThroughLink($this, (string) $referrer->referral_code, 'zara@example.com');

    $referral = Referral::query()->sole();
    expect($referral->referrer_id)->toBe($referrer->id)
        ->and($referral->referred->email)->toBe('zara@example.com')
        ->and($referral->status)->toBe(ReferralStatus::Pending)
        ->and(session()->has('referral_code'))->toBeFalse();
});

it('ignores the link for existing accounts and unknown codes', function (): void {
    $referrer = referrer();
    User::factory()->create(['email' => 'old@example.com']);

    signUpThroughLink($this, (string) $referrer->referral_code, 'old@example.com');
    $this->post('/logout');
    signUpThroughLink($this, 'ZZZZZZZZ', 'new@example.com');

    expect(Referral::query()->count())->toBe(0);
});

it('does not store anything for a signed-in visitor', function (): void {
    $referrer = referrer();

    $this->actingAs(User::factory()->create())->get('/r/'.$referrer->referral_code);

    expect(session()->has('referral_code'))->toBeFalse();
});

it('catches self-referral, same email and same phone', function (array $referred, ?string $reason): void {
    $referrer = referrer();
    $user = User::factory()->create($referred);

    expect(app(ReferralAbuseCheck::class)->reason($referrer, $user))->toBe($reason);
})->with([
    'different person' => [['email' => 'zara@example.com'], null],
    'gmail dots' => [['email' => 'amnak@gmail.com'], 'Same email address as the referrer'],
    'gmail plus tag' => [['email' => 'Amna.K+ride@googlemail.com'], 'Same email address as the referrer'],
    'same phone' => [['email' => 'other@example.com', 'phone' => '+923001234567'], 'Same phone number as the referrer'],
]);

it('rejects self-referral and team referrers with a reason', function (): void {
    $referrer = referrer();
    $admin = User::factory()->admin()->create();
    app(EnsureReferralCode::class)->handle($admin);

    expect(app(ReferralAbuseCheck::class)->reason($referrer, $referrer))->toBe('Self-referral')
        ->and(app(ReferralAbuseCheck::class)->reason($admin, User::factory()->create()))->toBe('The referrer is not an active customer');

    $twin = User::factory()->create(['email' => 'a.m.n.a.k@gmail.com']);
    $referral = app(AttachReferral::class)->handle((string) $referrer->referral_code, $twin);

    expect($referral?->status)->toBe(ReferralStatus::Rejected)
        ->and($referral?->rejection_reason)->toBe('Same email address as the referrer');
});

it('rewards the referrer once when the friend attends a paid session', function (): void {
    $referrer = referrer();
    $friend = User::factory()->create();
    app(AttachReferral::class)->handle((string) $referrer->referral_code, $friend);
    $booking = startedBooking($friend);

    app(ChangeBookingStatus::class)->handle($booking, BookingStatus::Attended);
    app(RewardReferral::class)->handle($booking->refresh());

    $referral = Referral::query()->sole();
    expect($referral->status)->toBe(ReferralStatus::Rewarded)
        ->and($referral->rewarded_booking_id)->toBe($booking->id)
        ->and(app(AccountCredit::class)->balance($referrer))->toBe(100_000)
        ->and(AccountCreditEntry::query()->sole()->reason)->toBe(AccountCreditReason::ReferralReward);

    Notification::assertSentToTimes($referrer, ReferralRewardEarned::class, 1);
});

it('counts a session paid with credits from a paid pack', function (): void {
    $referrer = referrer();
    $friend = User::factory()->create();
    app(AttachReferral::class)->handle((string) $referrer->referral_code, $friend);
    $order = Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $friend->id]);
    $pack = CreditPack::factory()->create(['order_id' => $order->id, 'user_id' => $friend->id]);
    $booking = Booking::factory()->create(['user_id' => $friend->id, 'order_id' => null, 'credit_pack_id' => $pack->id, 'slot_id' => Slot::factory()->at(CarbonImmutable::now()->subMinutes(random_int(30, 50_000)))->create()->id]);

    app(ChangeBookingStatus::class)->handle($booking, BookingStatus::Attended);

    expect(Referral::query()->sole()->status)->toBe(ReferralStatus::Rewarded);
});

it('does not reward unpaid, refunded or no-charge sessions, or a no-show', function (): void {
    $referrer = referrer();
    $friend = User::factory()->create();
    app(AttachReferral::class)->handle((string) $referrer->referral_code, $friend);

    $refunded = startedBooking($friend, OrderStatus::Refunded);
    $free = Booking::factory()->create(['user_id' => $friend->id, 'order_id' => null, 'slot_id' => Slot::factory()->at(CarbonImmutable::now()->subMinutes(random_int(30, 50_000)))->create()->id]);
    $noShow = startedBooking($friend);

    app(ChangeBookingStatus::class)->handle($refunded, BookingStatus::Attended);
    app(ChangeBookingStatus::class)->handle($free, BookingStatus::Attended);
    app(ChangeBookingStatus::class)->handle($noShow, BookingStatus::NoShow);

    expect(Referral::query()->sole()->status)->toBe(ReferralStatus::Pending)
        ->and(AccountCreditEntry::query()->exists())->toBeFalse();
});

it('rejects at reward time when the friend has since added the referrer\'s phone', function (): void {
    $referrer = referrer();
    $friend = User::factory()->create();
    app(AttachReferral::class)->handle((string) $referrer->referral_code, $friend);
    $friend->forceFill(['phone' => '+923001234567'])->save();

    app(ChangeBookingStatus::class)->handle(startedBooking($friend), BookingStatus::Attended);

    $referral = Referral::query()->sole();
    expect($referral->status)->toBe(ReferralStatus::Rejected)
        ->and($referral->rejection_reason)->toBe('Same phone number as the referrer')
        ->and(AccountCreditEntry::query()->exists())->toBeFalse();
});

it('rewards only once for several attended sessions', function (): void {
    $referrer = referrer();
    $friend = User::factory()->create();
    app(AttachReferral::class)->handle((string) $referrer->referral_code, $friend);

    app(ChangeBookingStatus::class)->handle(startedBooking($friend), BookingStatus::Attended);
    app(ChangeBookingStatus::class)->handle(startedBooking($friend), BookingStatus::Attended);

    expect(AccountCreditEntry::query()->count())->toBe(1);
});

it('lets admins adjust credit with a reason, never below zero', function (): void {
    $customer = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $credit = app(AccountCredit::class);

    $credit->adjust($customer, $admin, 50_000, 'Goodwill');

    expect(fn () => $credit->adjust($customer, $admin, -60_000, 'Too much'))->toThrow(CreditRejected::class)
        ->and(fn () => $credit->adjust($customer, $admin, 10_000, ''))->toThrow(CreditRejected::class)
        ->and(fn () => $credit->adjust($customer, User::factory()->staff()->create(), 10_000, 'x'))->toThrow(CreditRejected::class)
        ->and($credit->balance($customer))->toBe(50_000);
});

it('shows the referral page with the link and credit', function (): void {
    $user = referrer();
    app(AccountCredit::class)->adjust($user, User::factory()->admin()->create(), 20_000, 'Goodwill');

    $this->actingAs($user)->get(route('account.referrals'))
        ->assertOk()
        ->assertSee('/r/'.$user->referral_code)
        ->assertSee('PKR 200');
});

it('lets admins reject and reinstate referrals in the panel', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create());
    $referral = Referral::factory()->create();

    Livewire::test(ListReferrals::class)
        ->assertCanSeeTableRecords([$referral])
        ->callAction(TestAction::make('reject')->table($referral), data: ['reason' => 'Same household, two accounts']);

    expect($referral->refresh()->status)->toBe(ReferralStatus::Rejected);

    Livewire::test(ListReferrals::class)
        ->callAction(TestAction::make('reinstate')->table($referral), data: ['reason' => 'Checked: different people']);

    expect($referral->refresh()->status)->toBe(ReferralStatus::Pending)->and($referral->rejection_reason)->toBeNull();
    expect(fn () => app(ReviewReferral::class)->reject($referral, User::factory()->staff()->create(), 'x'))->toThrow(CreditRejected::class);
});

it('keeps referrals and credit from staff', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $customer = User::factory()->create();

    $this->actingAs($staff)->get(ReferralResource::getUrl())->assertForbidden();
    $this->actingAs(User::factory()->admin()->withMfa()->create())
        ->get(CustomerResource::getUrl('edit', ['record' => $customer]))
        ->assertOk()
        ->assertSee('Account credit');
});
