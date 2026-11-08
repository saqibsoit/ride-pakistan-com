<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\ReleaseExpiredHolds;
use App\Actions\Payments\CreateStripeCheckout;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Mail\OrderPaidMail;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\StripeEvent;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * A held single-session order with card payment started (session open).
 */
function cardOrder(TestCase $test, int $riders = 2, ?Product $product = null, bool $withSlot = true): Order
{
    $user = User::factory()->create();
    $riderIds = array_values(array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys()));
    $product ??= Product::factory()->create(['price_minor' => 1_000_000]);
    $slot = $withSlot ? Slot::factory()->create(['capacity' => 6]) : null;

    $order = app(CreateOrder::class)->handle($user, $product, $slot?->id, $withSlot ? $riderIds : []);
    app(CreateStripeCheckout::class)->handle($order);

    return $order->refresh();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function paidSession(Order $order, array $overrides = []): array
{
    return [
        'id' => $order->stripe_checkout_session_id ?? 'cs_test_x',
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'client_reference_id' => (string) $order->id,
        'metadata' => ['reference' => $order->reference, 'order_id' => (string) $order->id],
        'amount_total' => $order->amount_minor,
        'currency' => 'pkr',
        'payment_intent' => 'pi_test_'.$order->id,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $event
 */
function postWebhook(TestCase $test, array $event, ?string $signature = null): TestResponse
{
    $payload = (string) json_encode($event);

    return $test->call('POST', '/webhooks/stripe', server: array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature ?? FakeStripeGateway::sign($payload),
    ]), content: $payload);
}

beforeEach(function (): void {
    Mail::fake();
});

it('fulfils a paid order through FulfilOrder on a validly signed checkout.session.completed', function (): void {
    $order = cardOrder($this);

    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order)))
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => false]);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->stripe_payment_intent_id)->toBe('pi_test_'.$order->id)
        ->and(Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->count())->toBe(2)
        ->and(BookingHold::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and(Payment::query()->where('order_id', $order->id)->sole()->status)->toBe(PaymentStatus::Succeeded)
        ->and(StripeEvent::query()->sole()->processed_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'fulfilled')->where('subject_id', $order->id)->exists())->toBeTrue();

    Mail::assertQueued(OrderPaidMail::class, fn (OrderPaidMail $mail): bool => $mail->order->is($order) && $mail->hasTo($order->user->email));
});

it('renders the receipt with the brand line, reference, sessions and tickets', function (): void {
    $order = cardOrder($this);
    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order)))->assertOk();

    $order->refresh();
    $mail = new OrderPaidMail($order);
    $html = $mail->render();

    $mail->assertHasSubject('Payment received: '.$order->reference.' (Ride Pakistan by Destination Pakistan)');

    expect($html)->toContain($order->reference)
        ->toContain('PKR 20,000')
        ->toContain('Ride Pakistan by Destination Pakistan')
        ->toContain((string) Booking::query()->where('order_id', $order->id)->value('ticket_code'));
});

it('grants a credit pack for a paid package', function (): void {
    $order = cardOrder($this, product: Product::factory()->package(8, 6_000_000)->create(), withSlot: false);

    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order)))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditPack::query()->where('order_id', $order->id)->sole()->credits_total)->toBe(8);
});

it('rejects an invalid signature with 400 and changes nothing', function (): void {
    $order = cardOrder($this);
    $event = FakeStripeGateway::event('checkout.session.completed', paidSession($order));
    $payload = (string) json_encode($event);

    postWebhook($this, $event, FakeStripeGateway::sign($payload, secret: 'whsec_wrong'))->assertStatus(400);

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(StripeEvent::query()->exists())->toBeFalse()
        ->and(Booking::query()->exists())->toBeFalse();
});

it('rejects a missing signature, a stale timestamp and a tampered body with 400', function (): void {
    $order = cardOrder($this);
    $event = FakeStripeGateway::event('checkout.session.completed', paidSession($order));
    $payload = (string) json_encode($event);

    $this->call('POST', '/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json'], content: $payload)->assertStatus(400);

    postWebhook($this, $event, FakeStripeGateway::sign($payload, time() - 3600))->assertStatus(400);

    $tampered = $event;
    $tampered['data']['object']['amount_total'] = 1;
    $this->call('POST', '/webhooks/stripe', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => FakeStripeGateway::sign($payload),
    ], content: (string) json_encode($tampered))->assertStatus(400);

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('does not need a CSRF token or session', function (): void {
    $order = cardOrder($this);

    // The web group (sessions, CSRF) is not on this route.
    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order)))
        ->assertOk()
        ->assertCookieMissing((string) config('session.cookie'));
});

it('processes a duplicate event only once', function (): void {
    Event::fake([OrderPaid::class]);
    $order = cardOrder($this);
    $event = FakeStripeGateway::event('checkout.session.completed', paidSession($order));

    postWebhook($this, $event)->assertOk()->assertJson(['duplicate' => false]);
    postWebhook($this, $event)->assertOk()->assertJson(['duplicate' => true]);

    // A different event for the same session (e.g. async succeeded) is also harmless.
    postWebhook($this, FakeStripeGateway::event('checkout.session.async_payment_succeeded', paidSession($order)))->assertOk();

    expect(Booking::query()->where('order_id', $order->id)->count())->toBe(2)
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(StripeEvent::query()->count())->toBe(2);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('does not fulfil when the amount differs, and raises an alert', function (): void {
    Log::spy();
    $order = cardOrder($this);

    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order, ['amount_total' => 100])))->assertOk();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and(Booking::query()->exists())->toBeFalse()
        ->and($order->meta['needs_review'] ?? null)->toContain('Not fulfilled')
        ->and(Activity::query()->where('event', 'payment_mismatch')->where('subject_id', $order->id)->exists())->toBeTrue();

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, $order->reference))->once();
    Mail::assertNothingQueued();
});

it('does not fulfil when the currency differs', function (): void {
    $order = cardOrder($this);

    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order, ['currency' => 'usd'])))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->meta['needs_review'] ?? null)->not->toBeNull();
});

it('does not fulfil when the reference does not match the order id', function (): void {
    $order = cardOrder($this);
    $other = cardOrder($this);

    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order, [
        'metadata' => ['reference' => $other->reference],
    ])))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($other->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->meta['needs_review'] ?? null)->toContain('does not match');
});

it('ignores a completed session that is not paid yet, then fulfils on async success', function (): void {
    $order = cardOrder($this);

    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order, ['payment_status' => 'unpaid'])))->assertOk();
    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    postWebhook($this, FakeStripeGateway::event('checkout.session.async_payment_succeeded', paidSession($order)))->assertOk();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('keeps the order and its seats when the session expires, then holds:release frees them', function (): void {
    $order = cardOrder($this);
    $sessionId = (string) $order->stripe_checkout_session_id;

    postWebhook($this, FakeStripeGateway::event('checkout.session.expired', paidSession($order, ['status' => 'expired', 'payment_status' => 'unpaid'])))->assertOk();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->stripe_checkout_session_id)->toBeNull()
        ->and(BookingHold::query()->where('order_id', $order->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'card_expired')->exists())->toBeTrue();

    // The checkout page now offers card again and bank transfer for the same reference.
    $this->actingAs($order->user)->get(route('checkout.show', $order->reference))
        ->assertOk()
        ->assertSee('Try card again')
        ->assertSee('Pay by bank transfer')
        ->assertSee($order->reference);

    $this->travelTo($order->expires_at?->addMinute());
    app(ReleaseExpiredHolds::class)->handle();

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and(BookingHold::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and($this->stripe()->expired)->not->toContain($sessionId); // already expired at Stripe
});

it('records a failed async payment and keeps the order open', function (): void {
    $order = cardOrder($this);

    postWebhook($this, FakeStripeGateway::event('checkout.session.async_payment_failed', paidSession($order, ['payment_status' => 'unpaid'])))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->stripe_checkout_session_id)->toBeNull()
        ->and(Payment::query()->where('order_id', $order->id)->sole()->status)->toBe(PaymentStatus::Failed);
});

it('refunds automatically when a late payment can no longer be fulfilled', function (): void {
    $order = cardOrder($this, riders: 1);
    $slot = Slot::query()->findOrFail((int) $order->items()->value('slot_id'));

    // The order expired and someone else took every seat.
    $this->travelTo($order->expires_at?->addMinute());
    $this->stripe()->failRetrieve = true; // Stripe unreachable: order not expired yet
    app(ReleaseExpiredHolds::class)->handle();
    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    Booking::factory()->count($slot->capacity)->create(['slot_id' => $slot->id]);

    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order)))->assertOk();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and(Booking::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and($this->stripe()->refunds)->toHaveCount(1)
        ->and($this->stripe()->refunds[0]['params']['amount'])->toBe($order->amount_minor)
        ->and($order->amount_refunded_minor)->toBe($order->amount_minor)
        ->and($order->meta['needs_review'] ?? null)->toContain('Refunded automatically');
});

it('refunds automatically when the order was already expired', function (): void {
    $order = cardOrder($this, riders: 1);

    $this->travelTo($order->expires_at?->addMinute());
    app(ReleaseExpiredHolds::class)->handle();
    expect($order->refresh()->status)->toBe(OrderStatus::Expired);

    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', paidSession($order)))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($this->stripe()->refunds)->toHaveCount(1);
});

it('answers 500 and keeps nothing when processing fails, so the retry succeeds', function (): void {
    $order = cardOrder($this);
    $event = FakeStripeGateway::event('checkout.session.completed', paidSession($order));

    Payment::creating(function (): never {
        throw new RuntimeException('database hiccup');
    });

    postWebhook($this, $event)->assertStatus(500);

    expect(StripeEvent::query()->exists())->toBeFalse()
        ->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    Payment::flushEventListeners();

    postWebhook($this, $event)->assertOk()->assertJson(['duplicate' => false]);
    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('acknowledges event types it does not handle without storing them', function (): void {
    postWebhook($this, FakeStripeGateway::event('customer.created', ['id' => 'cus_1', 'object' => 'customer']))
        ->assertOk()
        ->assertJson(['ignored' => 'customer.created']);

    expect(StripeEvent::query()->exists())->toBeFalse();
});

it('logs and acknowledges a session for an unknown order', function (): void {
    postWebhook($this, FakeStripeGateway::event('checkout.session.completed', [
        'id' => 'cs_test_unknown', 'object' => 'checkout.session', 'payment_status' => 'paid',
        'client_reference_id' => null, 'metadata' => [], 'amount_total' => 100, 'currency' => 'pkr',
    ]))->assertOk();

    expect(StripeEvent::query()->sole()->processed_at)->not->toBeNull();
});
