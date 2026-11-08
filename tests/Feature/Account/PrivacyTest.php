<?php

declare(strict_types=1);

use App\Actions\Account\AnonymiseAccount;
use App\Actions\Account\AnonymiseDeletedAccounts;
use App\Actions\Account\RequestAccountDeletion;
use App\Actions\Account\RestoreAccount;
use App\Actions\Auth\RequestLoginCode;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Exceptions\AccountActionBlocked;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Models\BankTransfer;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Lead;
use App\Models\LoginCode;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Download my data
|--------------------------------------------------------------------------
*/

it('exports the customer’s own data as a JSON download', function (): void {
    $user = User::factory()->create(['name' => 'Sana', 'phone' => '+923001234567']);
    Rider::factory()->for($user)->create(['name' => 'Zara', 'emergency_contact_phone' => '+923339876543', 'notes' => 'Allergic to hay']);
    $order = Order::factory()->for($user)->status(OrderStatus::Paid)->create();
    Payment::factory()->create(['order_id' => $order->id, 'raw' => ['secret_payload' => 'pi_secret_123']]);
    BankTransfer::factory()->create(['order_id' => $order->id, 'screenshot_path' => 'transfers/private-file.png']);
    Lead::factory()->create(['user_id' => $user->id, 'message' => 'Do you ride in winter?']);

    $other = User::factory()->create(['name' => 'Stranger']);
    Rider::factory()->for($other)->create(['name' => 'Not mine']);

    $response = $this->actingAs($user)->post('/account/privacy/export');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=utf-8')
        ->assertDownload('ride-pakistan-my-data-'.now()->format('Y-m-d').'.json');

    $json = $response->streamedContent();
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($data['account']['name'])->toBe('Sana')
        ->and($data['account']['phone'])->toBe('+923001234567')
        ->and($data['riders'])->toHaveCount(1)
        ->and($data['riders'][0]['notes'])->toBe('Allergic to hay')
        ->and($data['riders'][0]['emergency_contact_phone'])->toBe('+923339876543')
        ->and($data['orders'][0]['reference'])->toBe($order->reference)
        ->and($data['orders'][0]['payments'])->toHaveCount(1)
        ->and($data['orders'][0]['bank_transfers'][0]['screenshot'])->toBe('held on file')
        ->and($data['enquiries'][0]['message'])->toBe('Do you ride in winter?')
        ->and($json)->not->toContain('Stranger')
        ->and($json)->not->toContain('Not mine')
        ->and($json)->not->toContain('pi_secret_123')
        ->and($json)->not->toContain('private-file.png')
        ->and($json)->not->toContain('app_authentication')
        ->and($json)->not->toContain('remember_token');

    expect(Activity::query()->where('event', 'exported')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('limits data exports', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->post('/account/privacy/export')->assertOk();
    }

    $this->actingAs($user)->post('/account/privacy/export')->assertTooManyRequests();
});

it('only lets a user export their own data', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect($user->can('exportData', $user))->toBeTrue()
        ->and($user->can('exportData', $other))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Delete my account
|--------------------------------------------------------------------------
*/

it('soft-deletes the account, signs it out and logs the request', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/account/privacy')->assertOk()->assertSee('Delete my account');

    $this->actingAs($user)->delete('/account', ['confirm' => '1'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
    $user = User::withTrashed()->findOrFail($user->id);
    expect($user->trashed())->toBeTrue()
        ->and($user->session_version)->toBe(1)
        ->and($user->isAnonymised())->toBeFalse()
        ->and(Activity::query()->where('event', 'deletion-requested')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('needs the confirmation box ticked', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->delete('/account')->assertSessionHasErrors('confirm');

    expect($user->fresh()?->trashed())->toBeFalse();
});

it('blocks deletion while there are upcoming bookings or unpaid orders', function (): void {
    $user = User::factory()->create();
    Booking::factory()->create(['rider_id' => Rider::factory()->for($user)]);
    Order::factory()->for($user)->status(OrderStatus::AwaitingVerification)->create();

    $this->actingAs($user)->get('/account/privacy')
        ->assertSee('You have 1 upcoming booking.', false)
        ->assertSee('You have 1 unpaid order still open.', false)
        ->assertDontSee('Yes, delete my account');

    $this->actingAs($user)->delete('/account', ['confirm' => '1'])
        ->assertRedirect(route('account.privacy'))
        ->assertSessionHasErrors('delete');

    expect($user->fresh()?->trashed())->toBeFalse();
});

it('allows deletion with past bookings, cancelled bookings and closed orders', function (): void {
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    Booking::factory()->create(['rider_id' => $rider->id, 'slot_id' => Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->subWeek())]);
    Booking::factory()->status(BookingStatus::Cancelled)->create(['rider_id' => $rider->id]);
    Order::factory()->for($user)->status(OrderStatus::Expired)->create();

    app(RequestAccountDeletion::class)->handle($user);

    expect($user->trashed())->toBeTrue();
});

it('needs explicit consent to forfeit unused credits', function (): void {
    $user = User::factory()->create();
    CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'credits_total' => 8, 'credits_used' => 2]);
    // Expired packs don't count.
    CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'expires_at' => now()->subDay()]);

    $this->actingAs($user)->get('/account/privacy')->assertSee('I understand I give up my 6 unused credits.');

    expect(fn () => app(RequestAccountDeletion::class)->handle($user))
        ->toThrow(AccountActionBlocked::class, 'Confirm that you give up your 6 unused credits.');

    app(RequestAccountDeletion::class)->handle($user, forfeitCredits: true);

    expect($user->trashed())->toBeTrue()
        ->and(Activity::query()->where('event', 'deletion-requested')->sole()->properties?->get('credits_forfeited'))->toBe(6);
});

it('does not let team accounts delete themselves', function (): void {
    $admin = User::factory()->admin()->create();

    expect($admin->can('deleteAccount', $admin))->toBeFalse()
        ->and(fn () => app(RequestAccountDeletion::class)->handle($admin))->toThrow(AccountActionBlocked::class);
});

it('ends the deleted account’s other sessions', function (): void {
    $user = User::factory()->create();

    // Another device signed in before the deletion.
    $this->actingAs($user)->withSession([EnsureSessionIsCurrent::SESSION_KEY => 0])->get('/account')->assertOk();

    app(RequestAccountDeletion::class)->handle($user);
    $user->restore(); // even if restored by an admin, the old session stays dead

    $this->actingAs($user->fresh() ?? $user)->withSession([EnsureSessionIsCurrent::SESSION_KEY => 0])
        ->get('/account')
        ->assertRedirect(route('login'));
});

it('restores an account inside the grace period only', function (): void {
    $recent = User::factory()->deletedDaysAgo(29)->create();
    $expired = User::factory()->deletedDaysAgo(31)->create();

    app(RestoreAccount::class)->handle($recent);

    expect($recent->fresh()?->trashed())->toBeFalse()
        ->and(fn () => app(RestoreAccount::class)->handle($expired))->toThrow(AccountActionBlocked::class);
});

/*
|--------------------------------------------------------------------------
| Anonymisation
|--------------------------------------------------------------------------
*/

it('anonymises personal data and keeps the order history', function (): void {
    $user = User::factory()->create(['name' => 'Sana Malik', 'email' => 'sana@example.com', 'phone' => '+923001234567', 'marketing_consent' => true]);
    $booked = Rider::factory()->for($user)->create(['name' => 'Zara Malik', 'notes' => 'Allergic', 'emergency_contact_phone' => '+923339876543']);
    $unused = Rider::factory()->for($user)->create(['name' => 'Never booked']);
    $order = Order::factory()->for($user)->status(OrderStatus::Paid)->create();
    $booking = Booking::factory()->create(['rider_id' => $booked->id, 'order_id' => $order->id, 'slot_id' => Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->subYear())]);
    $lead = Lead::factory()->create(['user_id' => $user->id, 'name' => 'Sana', 'email' => 'sana@example.com', 'message' => 'Hi']);
    $user->update(['name' => 'Sana M.']); // an audit-log entry with the name
    LoginCode::query()->create(['channel' => 'email', 'destination' => 'sana@example.com', 'code_hash' => str_repeat('a', 64), 'expires_at' => now()]);
    $user->delete();

    app(AnonymiseAccount::class)->handle($user);

    $user = User::withTrashed()->findOrFail($user->id);
    expect($user->isAnonymised())->toBeTrue()
        ->and($user->trashed())->toBeTrue()
        ->and($user->name)->toBeNull()
        ->and($user->email)->toBe('deleted-'.$user->id.'@anonymised.invalid')
        ->and($user->phone)->toBeNull()
        ->and($user->marketing_consent)->toBeFalse()
        ->and($booked->fresh()?->name)->toBe(AnonymiseAccount::FORMER_RIDER)
        ->and($booked->fresh()?->notes)->toBeNull()
        ->and($booked->fresh()?->emergency_contact_phone)->toBeNull()
        ->and(Rider::query()->find($unused->id))->toBeNull()
        ->and($order->fresh())->not->toBeNull()
        ->and($booking->fresh())->not->toBeNull()
        ->and($lead->fresh()?->email)->toBeNull()
        ->and($lead->fresh()?->message)->toBeNull()
        ->and(LoginCode::query()->count())->toBe(0);

    $log = DB::table('activity_log')->pluck('properties')->implode(' ');
    expect($log)->not->toContain('Sana')
        ->and($log)->not->toContain('Zara')
        ->and($log)->not->toContain('sana@example.com');

    // The address is free for a new account.
    expect(User::factory()->create(['email' => 'sana@example.com'])->exists)->toBeTrue();
});

it('anonymises after the grace period when there are no paid orders', function (): void {
    $due = User::factory()->deletedDaysAgo(31)->create();
    $inGrace = User::factory()->deletedDaysAgo(29)->create();
    $active = User::factory()->create();

    $this->artisan('accounts:anonymise')->expectsOutput('Anonymised 1 account.')->assertSuccessful();

    expect(User::withTrashed()->find($due->id)?->isAnonymised())->toBeTrue()
        ->and(User::withTrashed()->find($inGrace->id)?->isAnonymised())->toBeFalse()
        ->and($active->fresh()?->isAnonymised())->toBeFalse();
});

it('keeps accounts with a paid order inside the retention period', function (): void {
    $now = CarbonImmutable::now();

    $recentPayer = User::factory()->deletedDaysAgo(60)->create();
    Order::factory()->for($recentPayer)->status(OrderStatus::Paid)->create(['paid_at' => $now->subYears(6)->addDay()]);

    $oldPayer = User::factory()->deletedDaysAgo(60)->create();
    Order::factory()->for($oldPayer)->status(OrderStatus::Paid)->create(['paid_at' => $now->subYears(6)->subDay()]);

    // A refunded order was still paid: it counts for retention.
    $refunded = User::factory()->deletedDaysAgo(60)->create();
    Order::factory()->for($refunded)->status(OrderStatus::Refunded)->create(['paid_at' => $now->subYear()]);

    // An unpaid order does not hold the account back.
    $unpaid = User::factory()->deletedDaysAgo(60)->create();
    Order::factory()->for($unpaid)->status(OrderStatus::Expired)->create();

    expect(app(AnonymiseDeletedAccounts::class)->handle($now))->toBe(2)
        ->and(User::withTrashed()->find($recentPayer->id)?->isAnonymised())->toBeFalse()
        ->and(User::withTrashed()->find($refunded->id)?->isAnonymised())->toBeFalse()
        ->and(User::withTrashed()->find($oldPayer->id)?->isAnonymised())->toBeTrue()
        ->and(User::withTrashed()->find($unpaid->id)?->isAnonymised())->toBeTrue();

    // Once the retention period passes, the next run picks it up.
    expect(app(AnonymiseDeletedAccounts::class)->handle($now->addDays(2)))->toBe(1)
        ->and(User::withTrashed()->find($recentPayer->id)?->isAnonymised())->toBeTrue();
});

it('does not anonymise an account twice', function (): void {
    $user = User::factory()->deletedDaysAgo(40)->create();

    expect(app(AnonymiseDeletedAccounts::class)->handle())->toBe(1)
        ->and(app(AnonymiseDeletedAccounts::class)->handle())->toBe(0);
});

it('locks an account kept for retention out of sign-in', function (): void {
    $user = User::factory()->deletedDaysAgo(60)->create();
    Order::factory()->for($user)->status(OrderStatus::Paid)->create();

    expect(app(RequestLoginCode::class)->handle($user->email))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Sign out everywhere
|--------------------------------------------------------------------------
*/

it('signs out every session, this one included', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession([EnsureSessionIsCurrent::SESSION_KEY => 0])
        ->post('/account/sign-out-everywhere')
        ->assertRedirect(route('login'));

    $this->assertGuest();
    $user->refresh();
    expect($user->session_version)->toBe(1)
        ->and(Activity::query()->where('event', 'signed-out-everywhere')->where('subject_id', $user->id)->exists())->toBeTrue();

    // Another device still carrying version 0 is sent to sign in.
    $this->actingAs($user)->withSession([EnsureSessionIsCurrent::SESSION_KEY => 0])
        ->get('/account')
        ->assertRedirect(route('login'));
    $this->assertGuest();

    // A fresh sign-in (version 1) works.
    $this->actingAs($user)->withSession([EnsureSessionIsCurrent::SESSION_KEY => 1])->get('/account')->assertOk();
});

it('ends old admin panel sessions too', function (): void {
    $admin = User::factory()->admin()->withMfa()->create(['session_version' => 2]);

    $this->actingAs($admin)->withSession([EnsureSessionIsCurrent::SESSION_KEY => 1])
        ->get('/'.trim((string) config('app.admin_path'), '/'))
        ->assertRedirect();

    $this->assertGuest();
});
