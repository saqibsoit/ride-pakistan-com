<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Credit\AccountCredit;
use App\Actions\Discounts\ChangeOrderDiscounts;
use App\Actions\Discounts\CompleteCoveredOrder;
use App\Actions\Discounts\RefundCoveredOrder;
use App\Actions\Payments\CreateStripeCheckout;
use App\Actions\Payments\RefundOrder;
use App\Actions\Referrals\RewardReferral;
use App\Enums\AccountCreditReason;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\ReferralStatus;
use App\Enums\VoucherStatus;
use App\Exceptions\BookingRejected;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AccountCreditEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Referral;
use App\Models\Rider;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\FakeStripeGateway;

/**
 * A paid card order: 2 riders at PKR 10,000 (subtotal 20,000), with a
 * PKR 5,000 gift voucher and PKR 3,000 of account credit, so PKR 12,000
 * was charged by card.
 *
 * @return array{0: Order, 1: Voucher}
 */
function drPaidOrder(bool $withPromo = false): array
{
    $user = User::factory()->create();
    $product = Product::factory()->create(['price_minor' => 1_000_000]);
    $riderIds = array_map('intval', Rider::factory()->count(2)->for($user)->create()->modelKeys());
    $order = app(CreateOrder::class)->handle($user, $product, nearSlot(5, 8, ['capacity' => 6])->id, $riderIds);
    $voucher = Voucher::factory()->create(['initial_minor' => 500_000, 'balance_minor' => 500_000]);
    AccountCreditEntry::query()->create(['user_id' => $user->id, 'delta_minor' => 300_000, 'reason' => AccountCreditReason::Adjusted, 'note' => 'Test']);

    $change = app(ChangeOrderDiscounts::class);

    if ($withPromo) {
        $change->applyCode($order, $user, PromoCode::factory()->fixed(200_000)->create()->code);
    }

    $change->applyCode($order, $user, $voucher->code);
    $change->useCredit($order, $user, true);

    app(CreateStripeCheckout::class)->handle($order->refresh());
    $order->refresh()->forceFill(['stripe_payment_intent_id' => 'pi_test_'.$order->id])->save();

    return [app(FulfilOrder::class)->handle($order)->refresh(), $voucher];
}

function drAdmin(): User
{
    return User::factory()->admin()->withMfa()->create();
}

beforeEach(function (): void {
    Notification::fake();
});

it('gives back the same share of voucher and credit as of the card money', function (): void {
    [$order, $voucher] = drPaidOrder();
    expect($order->amount_minor)->toBe(1_200_000)->and($voucher->refresh()->balance_minor)->toBe(0);

    // Refund a quarter of the card money (PKR 3,000 of 12,000).
    app(RefundOrder::class)->handle($order, drAdmin(), 300_000, 'One rider ill');

    expect($this->stripe()->refunds[0]['params']['amount'])->toBe(300_000)
        ->and($voucher->refresh()->balance_minor)->toBe(125_000) // a quarter of 5,000
        ->and($voucher->status)->toBe(VoucherStatus::Active)
        ->and(app(AccountCredit::class)->balance($order->user))->toBe(75_000); // a quarter of 3,000

    // The rest: everything else comes back, nothing twice.
    app(RefundOrder::class)->handle($order->refresh(), drAdmin(), 900_000, 'Session cancelled');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($voucher->refresh()->balance_minor)->toBe(500_000)
        ->and(app(AccountCredit::class)->balance($order->user))->toBe(300_000);
});

it('never lets rounding give back more than was used', function (): void {
    [$order, $voucher] = drPaidOrder();

    foreach ([100_000, 100_000, 100_000] as $amount) {
        app(RefundOrder::class)->handle($order->refresh(), drAdmin(), $amount, 'Partial');
    }

    // 3,000 of 12,000 refunded in thirds: floor at each step, exact in total.
    expect($voucher->refresh()->balance_minor)->toBe(125_000);

    app(RefundOrder::class)->handle($order->refresh(), drAdmin(), $order->refresh()->amount_minor - $order->amount_refunded_minor, 'Rest');
    expect($voucher->refresh()->balance_minor)->toBe(500_000)
        ->and(app(AccountCredit::class)->balance($order->user))->toBe(300_000);
});

it('caps card refunds at what was charged, not the subtotal', function (): void {
    [$order] = drPaidOrder(withPromo: true); // 20,000 − 2,000 − 5,000 − 3,000 = 10,000

    expect($order->amount_minor)->toBe(1_000_000)
        ->and(fn () => app(RefundOrder::class)->handle($order, drAdmin(), 1_000_001, 'Too much'))->toThrow(BookingRejected::class);

    app(RefundOrder::class)->handle($order, drAdmin(), 1_000_000, 'All');

    // The promo stays used.
    expect(PromoRedemption::query()->where('order_id', $order->id)->exists())->toBeTrue();
});

it('gives the voucher share as account credit when the voucher expired since', function (): void {
    [$order, $voucher] = drPaidOrder();
    $voucher->forceFill(['expires_at' => now()->subDay(), 'status' => VoucherStatus::Expired])->save();

    app(RefundOrder::class)->handle($order, drAdmin(), 1_200_000, 'All');

    expect($voucher->refresh()->balance_minor)->toBe(0)
        ->and((int) AccountCreditEntry::query()->where('user_id', $order->user_id)->where('reason', AccountCreditReason::VoucherReturned)->sum('delta_minor'))->toBe(500_000)
        ->and(app(AccountCredit::class)->balance($order->user))->toBe(800_000);
});

it('gives the share back for a refund made in the Stripe dashboard too', function (): void {
    [$order, $voucher] = drPaidOrder();

    $payload = (string) json_encode(FakeStripeGateway::event('charge.refunded', [
        'id' => 'ch_test_1', 'object' => 'charge', 'payment_intent' => 'pi_test_'.$order->id, 'amount_refunded' => 600_000,
    ]));
    $this->call('POST', '/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => FakeStripeGateway::sign($payload)], content: $payload)->assertOk();

    expect($order->refresh()->amount_refunded_minor)->toBe(600_000)
        ->and($voucher->refresh()->balance_minor)->toBe(250_000)
        ->and(app(AccountCredit::class)->balance($order->user))->toBe(150_000);
});

it('cancels an order paid fully by voucher and credit and gives both back', function (): void {
    $user = User::factory()->create();
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(['price_minor' => 800_000]), nearSlot(6, 8, ['capacity' => 6])->id, [Rider::factory()->for($user)->create()->id]);
    $voucher = Voucher::factory()->create(['initial_minor' => 500_000, 'balance_minor' => 500_000]);
    AccountCreditEntry::query()->create(['user_id' => $user->id, 'delta_minor' => 300_000, 'reason' => AccountCreditReason::Adjusted, 'note' => 'Test']);
    app(ChangeOrderDiscounts::class)->applyCode($order, $user, $voucher->code);
    app(ChangeOrderDiscounts::class)->useCredit($order, $user, true);
    app(CompleteCoveredOrder::class)->handle($order->refresh(), $user);

    expect(fn () => app(RefundCoveredOrder::class)->handle($order, User::factory()->staff()->create(), 'No'))->toThrow(BookingRejected::class);

    Livewire::actingAs(drAdmin())->test(ViewOrder::class, ['record' => $order->reference])
        ->assertActionHidden('refund')
        ->callAction('refund_covered', data: ['reason' => 'Customer can’t come'])
        ->assertNotified('Cancelled; balances restored');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->bookings()->first()?->status)->toBe(BookingStatus::Cancelled)
        ->and($voucher->refresh()->balance_minor)->toBe(500_000)
        ->and(app(AccountCredit::class)->balance($user))->toBe(300_000);
});

it('rewards the referrer for a friend’s booking paid by gift voucher', function (): void {
    $referrer = User::factory()->create(['email' => 'amna@example.com', 'phone' => '+923001111111']);
    $friend = User::factory()->create(['email' => 'sara@example.com', 'phone' => '+923002222222']);
    $referral = Referral::factory()->create(['referrer_id' => $referrer->id, 'referred_id' => $friend->id]);

    $order = app(CreateOrder::class)->handle($friend, Product::factory()->create(['price_minor' => 500_000]), nearSlot(4, 8, ['capacity' => 6])->id, [Rider::factory()->for($friend)->create()->id]);
    app(ChangeOrderDiscounts::class)->applyCode($order, $friend, Voucher::factory()->create()->code);
    app(CompleteCoveredOrder::class)->handle($order->refresh(), $friend);

    $booking = $order->bookings()->sole();
    $booking->forceFill(['status' => BookingStatus::Attended])->save();
    app(RewardReferral::class)->handle($booking);

    expect($referral->refresh()->status)->toBe(ReferralStatus::Rewarded);
});
