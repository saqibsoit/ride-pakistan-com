<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Payments\CreateStripeCheckout;
use App\Actions\Payments\RefundOrder;
use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\CreditReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BookingRejected;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Booking;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/** A card order paid and fulfilled (as the webhook would), for 2 riders unless a package. */
function paidCardOrder(?Product $product = null, int $riders = 2): Order
{
    $user = User::factory()->create();
    $riderIds = array_values(array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys()));
    $product ??= Product::factory()->create(['price_minor' => 1_000_000]);
    $slot = Slot::factory()->at(CarbonImmutable::now()->addDays(5))->create(['capacity' => 6]);

    $order = app(CreateOrder::class)->handle($user, $product, $slot->id, $riderIds);
    app(CreateStripeCheckout::class)->handle($order);

    $order->refresh();
    $order->stripe_payment_intent_id = 'pi_test_'.$order->id;
    $order->save();

    return app(FulfilOrder::class)->handle($order)->refresh();
}

function refundAdmin(): User
{
    return User::factory()->admin()->withMfa()->create();
}

/**
 * @param  array<string, mixed>  $charge
 */
function postRefundWebhook(TestCase $test, array $charge): TestResponse
{
    $payload = (string) json_encode(FakeStripeGateway::event('charge.refunded', $charge));

    return $test->call('POST', '/webhooks/stripe', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => FakeStripeGateway::sign($payload),
    ], content: $payload);
}

it('fully refunds a card order: Stripe refund, status, payment row, bookings cancelled', function (): void {
    $order = paidCardOrder();
    $admin = refundAdmin();

    app(RefundOrder::class)->handle($order, $admin, $order->amount_minor, 'Customer is ill');

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->amount_refunded_minor)->toBe(2_000_000)
        ->and($this->stripe()->refunds)->toHaveCount(1)
        ->and($this->stripe()->refunds[0]['params'])->toMatchArray(['payment_intent' => 'pi_test_'.$order->id, 'amount' => 2_000_000])
        ->and(Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Refunded)->sole()->provider_id)->toBe('re_test_1')
        ->and(Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Cancelled)->count())->toBe(2)
        ->and(Activity::query()->where('event', 'refunded')->where('subject_id', $order->id)->sole()->causer_id)->toBe($admin->id);
});

it('voids unused credits and cancels credit bookings when a package is fully refunded', function (): void {
    $order = paidCardOrder(Product::factory()->package(8, 6_000_000)->create(), riders: 1);
    $pack = CreditPack::query()->where('order_id', $order->id)->sole();

    // A later session booked with the pack's credits is cancelled too.
    $rider = Rider::query()->where('user_id', $order->user_id)->sole();
    $later = Slot::factory()->at(CarbonImmutable::now()->addDays(12))->create();
    app(BookWithCredit::class)->handle($order->user, $later->id, [$rider->id]);

    expect($pack->refresh()->creditsRemaining())->toBe(6);

    app(RefundOrder::class)->handle($order, refundAdmin(), $order->amount_minor, 'Moving abroad');

    $pack->refresh();

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($pack->status)->toBe(CreditPackStatus::Revoked)
        ->and($pack->creditsRemaining())->toBe(0)
        ->and(Booking::query()->where('credit_pack_id', $pack->id)->where('status', BookingStatus::Confirmed)->exists())->toBeFalse()
        ->and(CreditLedgerEntry::query()->where('credit_pack_id', $pack->id)->where('reason', CreditReason::Adjusted)->sole()->delta)->toBe(-6);
});

it('partially refunds with the credits and bookings the admin chose', function (): void {
    $order = paidCardOrder(Product::factory()->package(8, 6_000_000)->create(), riders: 2);
    $pack = CreditPack::query()->where('order_id', $order->id)->sole();
    $bookings = Booking::query()->where('order_id', $order->id)->orderBy('id')->get();

    app(RefundOrder::class)->handle($order, refundAdmin(), 1_500_000, 'Two sessions not wanted', [$bookings[0]->id], 1);

    $pack->refresh();

    expect($order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($order->amount_refunded_minor)->toBe(1_500_000)
        ->and($bookings[0]->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($bookings[1]->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($pack->status)->toBe(CreditPackStatus::Active)
        ->and($pack->creditsRemaining())->toBe(5) // 8 - 2 used - 1 removed; the cancelled booking's credit stays used
        ->and(CreditLedgerEntry::query()->where('credit_pack_id', $pack->id)->where('reason', CreditReason::Adjusted)->sole()->delta)->toBe(-1);
});

it('refunds a partially refunded order again, up to the total', function (): void {
    $order = paidCardOrder();
    $admin = refundAdmin();

    app(RefundOrder::class)->handle($order, $admin, 500_000, 'First part');
    app(RefundOrder::class)->handle($order->refresh(), $admin, 500_000, 'Second part');

    expect($order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($order->amount_refunded_minor)->toBe(1_000_000)
        ->and($this->stripe()->refunds)->toHaveCount(2);

    app(RefundOrder::class)->handle($order, $admin, 1_000_000, 'The rest');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('refuses over-refunds, missing reasons, non-admins, unpaid orders and bad choices', function (): void {
    $order = paidCardOrder();
    $admin = refundAdmin();
    $refund = app(RefundOrder::class);

    $reason = function (callable $callback): ?string {
        try {
            $callback();
        } catch (BookingRejected $e) {
            return $e->getMessage();
        }

        return null;
    };

    expect($reason(fn () => $refund->handle($order, $admin, $order->amount_minor + 1, 'Too much')))->toContain('not yet refunded')
        ->and($reason(fn () => $refund->handle($order, $admin, 0, 'Nothing')))->toContain('between')
        ->and($reason(fn () => $refund->handle($order, $admin, 100, '  ')))->toContain('reason')
        ->and($reason(fn () => $refund->handle($order, $order->user, 100, 'Me')))->toContain('Only admins')
        ->and($reason(fn () => $refund->handle($order, $admin, 100, 'Wrong booking', [999_999])))->toContain('not upcoming bookings')
        ->and($reason(fn () => $refund->handle($order, $admin, 100, 'No pack', [], 1)))->toContain('more credits')
        ->and($reason(fn () => $refund->handle(Order::factory()->create(), $admin, 100, 'Unpaid')))->toContain('Only paid card orders')
        ->and($this->stripe()->refunds)->toBeEmpty();
});

it('changes nothing when Stripe rejects the refund', function (): void {
    $order = paidCardOrder();
    $this->stripe()->failRefund = true;

    expect(fn () => app(RefundOrder::class)->handle($order, refundAdmin(), $order->amount_minor, 'Ill'))
        ->toThrow(BookingRejected::class, 'Stripe did not accept');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->count())->toBe(2);
});

it('does not book an admin refund twice when charge.refunded arrives', function (): void {
    $order = paidCardOrder();
    app(RefundOrder::class)->handle($order, refundAdmin(), 500_000, 'Partial');

    postRefundWebhook($this, [
        'id' => 'ch_test_1', 'object' => 'charge', 'payment_intent' => $order->stripe_payment_intent_id,
        'amount' => 2_000_000, 'amount_refunded' => 500_000, 'currency' => 'pkr', 'refunded' => false,
    ])->assertOk();

    expect($order->refresh()->amount_refunded_minor)->toBe(500_000)
        ->and(Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Refunded)->count())->toBe(1)
        ->and($order->meta['needs_review'] ?? null)->toBeNull();
});

it('records a refund made in the Stripe dashboard and flags the order for review', function (): void {
    $order = paidCardOrder();

    postRefundWebhook($this, [
        'id' => 'ch_test_1', 'object' => 'charge', 'payment_intent' => $order->stripe_payment_intent_id,
        'amount' => 2_000_000, 'amount_refunded' => 2_000_000, 'currency' => 'pkr', 'refunded' => true,
    ])->assertOk();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->amount_refunded_minor)->toBe(2_000_000)
        ->and($order->meta['needs_review'] ?? null)->toContain('outside the admin panel')
        // Money only: bookings and credits are left for the admin to review.
        ->and(Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->count())->toBe(2);
});

it('refunds from the admin order page and shows the review flag', function (): void {
    $order = paidCardOrder();
    $this->actingAs(refundAdmin());

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('refund')
        ->callAction('refund', data: ['full' => true, 'reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('refund', data: ['full' => false, 'amount' => 5_000, 'reason' => 'Goodwill'])
        ->assertNotified('Refunded PKR 5,000');

    expect($order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($order->amount_refunded_minor)->toBe(500_000);
});

it('hides the refund action from staff and for unpaid orders', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create());

    Livewire::test(ViewOrder::class, ['record' => Order::factory()->create()->getRouteKey()])
        ->assertActionHidden('refund');

    $this->actingAs(User::factory()->staff()->withMfa()->create());
    $this->get('/test-admin/orders/'.paidCardOrder()->getRouteKey())->assertForbidden();
});

it('lets an admin clear a payment alert', function (): void {
    $order = paidCardOrder();
    $order->meta = [...($order->meta ?? []), 'needs_review' => 'Something odd'];
    $order->save();
    $this->actingAs(refundAdmin());

    $this->get('/test-admin/orders')->assertOk();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('Something odd')
        ->callAction(TestAction::make('clear_review'))
        ->assertNotified('Marked as reviewed');

    expect($order->refresh()->meta['needs_review'] ?? null)->toBeNull();
});
