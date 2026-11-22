<?php

declare(strict_types=1);

use App\Actions\Booking\FulfilOrder;
use App\Actions\Events\CancelEventBooking;
use App\Actions\Events\CancelEventOccurrence;
use App\Actions\Events\CreateBalanceCheckout;
use App\Actions\Events\CreateEventOrder;
use App\Actions\Events\MarkBalancePaid;
use App\Actions\Events\RespondToCancelledEventDate;
use App\Actions\Events\SendBalanceReminders;
use App\Actions\Payments\CreateStripeCheckout;
use App\Enums\BalanceMethod;
use App\Enums\BalanceStatus;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\EventBalancePaid;
use App\Notifications\EventBalanceReminder;
use App\Notifications\EventBookingCancelled;
use App\Notifications\EventDateCancelled;
use App\Support\OrderRevenue;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/** A deposit event order for $people people, paid by card through the real webhook. */
function paidEventOrder(TestCase $test, RideEvent $event, Slot $date, int $people = 1): Order
{
    $order = app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, $people));
    app(CreateStripeCheckout::class)->handle($order);
    completeCardPayment($test, $order);

    return $order->refresh();
}

/** Stripe's webhook for a paid balance session. */
function payBalanceByWebhook(TestCase $test, Order $order, ?int $amount = null): void
{
    $balance = $order->balance()->firstOrFail();
    $sessionId = (string) $balance->stripe_checkout_session_id;
    fakeStripe()->complete($sessionId);

    $payload = (string) json_encode(FakeStripeGateway::event('checkout.session.completed', [
        'id' => $sessionId,
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'client_reference_id' => 'balance:'.$balance->id,
        'metadata' => ['kind' => 'balance', 'reference' => $order->reference, 'order_balance_id' => (string) $balance->id],
        'amount_total' => $amount ?? $balance->amount_minor,
        'currency' => 'pkr',
        'payment_intent' => 'pi_balance_'.$balance->id,
    ]));

    $test->call('POST', '/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => FakeStripeGateway::sign($payload)], content: $payload)->assertOk();
}

beforeEach(function (): void {
    Notification::fake();
});

it('charges only the deposit by card and confirms the seats with the balance still due', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $date = eventDate($event);

    $order = paidEventOrder($this, $event, $date, 2);

    $line = fakeStripe()->created[0]['params']['line_items'][0];

    expect($line['price_data']['unit_amount'])->toBe(1_000_000)
        ->and($line['price_data']['product_data']['name'])->toContain('50% deposit')
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and(Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->count())->toBe(2)
        ->and($order->balance->status)->toBe(BalanceStatus::Due)
        ->and($order->balance->amount_minor)->toBe(1_000_000)
        ->and(OrderRevenue::of($order))->toBe(1_000_000);
});

it('takes the balance online from the manage link, once, and counts it as revenue', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $order = paidEventOrder($this, $event, eventDate($event), 1);

    $url = app(CreateBalanceCheckout::class)->handle($order);
    $params = fakeStripe()->lastParams();

    expect($url)->not->toBe('')
        ->and($params['metadata']['kind'])->toBe('balance')
        ->and($params['line_items'][0]['price_data']['unit_amount'])->toBe(500_000)
        ->and($params['client_reference_id'])->toBe('balance:'.$order->balance->id);

    payBalanceByWebhook($this, $order);
    payBalanceByWebhook($this, $order); // a repeat delivery

    $balance = $order->balance()->firstOrFail();

    expect($balance->status)->toBe(BalanceStatus::Paid)
        ->and($balance->method)->toBe(BalanceMethod::CardOnline)
        ->and(Payment::query()->where('order_id', $order->id)->where('purpose', PaymentPurpose::Balance)->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(OrderRevenue::of($order))->toBe(1_000_000)
        ->and(fn () => app(CreateBalanceCheckout::class)->handle($order))->toThrow(BookingRejected::class);

    Notification::assertSentTo($order->user, EventBalancePaid::class);
});

it('never marks a balance paid when the amount doesn’t match', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $order = paidEventOrder($this, $event, eventDate($event), 1);
    app(CreateBalanceCheckout::class)->handle($order);

    payBalanceByWebhook($this, $order, 100_000);

    expect($order->balance()->firstOrFail()->status)->toBe(BalanceStatus::Due)
        ->and($order->refresh()->meta['needs_review'] ?? null)->toContain('does not match');
});

it('lets an admin mark the balance paid in cash, and only an admin', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $order = paidEventOrder($this, $event, eventDate($event), 1);
    $balance = $order->balance()->firstOrFail();
    $admin = User::factory()->admin()->create();

    expect(fn () => app(MarkBalancePaid::class)->handle($balance, User::factory()->staff()->create(), BalanceMethod::Cash))
        ->toThrow(AuthorizationException::class);

    app(MarkBalancePaid::class)->handle($balance, $admin, BalanceMethod::Cash);
    app(MarkBalancePaid::class)->handle($balance, $admin, BalanceMethod::Cash); // twice: no change

    $payments = Payment::query()->where('order_id', $order->id)->where('purpose', PaymentPurpose::Balance)->get();

    expect($balance->refresh()->status)->toBe(BalanceStatus::Paid)
        ->and($balance->recorded_by)->toBe($admin->id)
        ->and($payments)->toHaveCount(1)
        ->and($payments->first()->amount_minor)->toBe(500_000)
        ->and(OrderRevenue::of($order))->toBe(1_000_000);
});

it('sends one balance reminder before it is due', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $order = paidEventOrder($this, $event, eventDate($event, days: 1, hour: 23), 1);

    expect(app(SendBalanceReminders::class)->handle())->toBe(1)
        ->and(app(SendBalanceReminders::class)->handle())->toBe(0);

    Notification::assertSentTo($order->user, EventBalanceReminder::class);
});

it('keeps the deposit when the customer cancels, before or after the cutoff', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create(['cancellation_cutoff_days' => 7]);

    $early = paidEventOrder($this, $event, eventDate($event, days: 20), 1);
    $late = paidEventOrder($this, $event, eventDate($event, days: 3), 1);

    expect(app(CancelEventBooking::class)->quote($early)['refund'])->toBe(0);

    $resultEarly = app(CancelEventBooking::class)->handle($early, $early->user);
    $resultLate = app(CancelEventBooking::class)->handle($late, $late->user);

    expect($resultEarly['refund'])->toBe(0)
        ->and($resultLate['refund'])->toBe(0)
        ->and(fakeStripe()->refunds)->toBe([])
        ->and($early->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($early->balance->status)->toBe(BalanceStatus::Void)
        ->and(Booking::query()->where('order_id', $early->id)->value('status'))->toBe(BookingStatus::Cancelled)
        ->and(OrderRevenue::of($early))->toBe(500_000);

    Notification::assertSentTo($early->user, EventBookingCancelled::class, fn (EventBookingCancelled $n): bool => $n->refundMinor === 0);
});

it('refunds a paid balance, never the deposit, when the customer cancels before the cutoff', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create(['cancellation_cutoff_days' => 7]);
    $order = paidEventOrder($this, $event, eventDate($event, days: 20), 1);
    app(CreateBalanceCheckout::class)->handle($order);
    payBalanceByWebhook($this, $order);

    $result = app(CancelEventBooking::class)->handle($order->refresh(), $order->user);

    expect($result['refund'])->toBe(500_000)
        ->and($result['automatic'])->toBe(500_000)
        ->and(fakeStripe()->refunds)->toHaveCount(1)
        ->and(fakeStripe()->refunds[0]['params']['payment_intent'])->toBe('pi_balance_'.$order->balance->id)
        ->and($order->balance()->firstOrFail()->status)->toBe(BalanceStatus::Refunded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->amount_refunded_minor)->toBe(0)
        ->and(OrderRevenue::of($order))->toBe(500_000);
});

it('refunds nothing after the cutoff even when the balance was paid', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create(['cancellation_cutoff_days' => 7]);
    $order = paidEventOrder($this, $event, eventDate($event, days: 3), 1);
    app(MarkBalancePaid::class)->handle($order->balance, User::factory()->admin()->create(), BalanceMethod::Cash);

    expect(app(CancelEventBooking::class)->handle($order->refresh(), $order->user)['refund'])->toBe(0)
        ->and(fakeStripe()->refunds)->toBe([]);
});

it('refunds a full-payment event with no non-refundable share before the cutoff', function (): void {
    $event = RideEvent::factory()->published()->create(['non_refundable_percent' => 0, 'cancellation_cutoff_days' => 7]);
    $order = paidEventOrder($this, $event, eventDate($event, days: 20), 1);

    $result = app(CancelEventBooking::class)->handle($order, $order->user);

    expect($result['refund'])->toBe(1_000_000)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(fakeStripe()->refunds[0]['params']['amount'])->toBe(1_000_000);
});

it('flags bank transfer money for an admin instead of refunding it by card', function (): void {
    $event = RideEvent::factory()->published()->create(['non_refundable_percent' => 0]);
    $date = eventDate($event, days: 20);
    $order = app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 1));
    app(FulfilOrder::class)->handle($order);
    $order->refresh();
    $order->forceFill(['payment_method' => PaymentMethod::BankTransfer->value])->save();

    $result = app(CancelEventBooking::class)->handle($order, $order->user);

    expect($result['manual'])->toBe(1_000_000)
        ->and(fakeStripe()->refunds)->toBe([])
        ->and($order->refresh()->meta['needs_review'])->toContain('PKR 10,000');
});

it('lets only the owner or an admin cancel, and not after the date has started', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $order = paidEventOrder($this, $event, eventDate($event), 1);

    expect(app(CancelEventBooking::class)->canCancel($order, User::factory()->create()))->toBeFalse()
        ->and(app(CancelEventBooking::class)->canCancel($order, User::factory()->admin()->create()))->toBeTrue()
        ->and(app(CancelEventBooking::class)->canCancel($order, $order->user))->toBeTrue();

    $this->travelTo($order->items()->firstOrFail()->slot->starts_at->addMinute());

    expect(app(CancelEventBooking::class)->canCancel($order->refresh(), $order->user))->toBeFalse();
});

it('gives a full refund, deposit included, when we cancel the date and the customer chooses it', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $date = eventDate($event, days: 3);
    $order = paidEventOrder($this, $event, $date, 2);
    app(MarkBalancePaid::class)->handle($order->balance, User::factory()->admin()->create(), BalanceMethod::Cash);

    app(CancelEventOccurrence::class)->handle($date, SlotStatus::Cancelled, User::factory()->admin()->create(), 'Heavy rain forecast');

    $order->refresh();

    expect(RespondToCancelledEventDate::pending($order))->toBeTrue()
        ->and($order->meta['needs_review'])->toContain('cancelled')
        ->and(Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->count())->toBe(2);

    Notification::assertSentTo($order->user, EventDateCancelled::class);

    $result = app(RespondToCancelledEventDate::class)->refund($order, $order->user);

    expect($result['refund'])->toBe(2_000_000)
        ->and($result['automatic'])->toBe(1_000_000) // the card deposit
        ->and($result['manual'])->toBe(1_000_000) // the cash balance, for an admin
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->count())->toBe(0)
        ->and(RespondToCancelledEventDate::pending($order))->toBeFalse()
        ->and(Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Refunded)->sum('amount_minor'))->toEqual(1_000_000);
});

it('moves everyone to another date when we cancel and the customer chooses that', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $date = eventDate($event, days: 3);
    $other = eventDate($event, days: 10, capacity: 5);
    $order = paidEventOrder($this, $event, $date, 2);

    app(CancelEventOccurrence::class)->handle($date, SlotStatus::Cancelled, User::factory()->admin()->create(), 'Horse lame');

    expect(RespondToCancelledEventDate::alternatives($order->refresh())->modelKeys())->toBe([$other->id]);

    app(RespondToCancelledEventDate::class)->move($order, $other->id, $order->user);

    expect(Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->pluck('slot_id')->unique()->all())->toBe([$other->id])
        ->and($order->balance()->firstOrFail()->due_at->equalTo($other->starts_at))->toBeTrue()
        ->and($order->refresh()->meta['needs_review'] ?? null)->toBeNull()
        ->and(fakeStripe()->refunds)->toBe([]);
});

it('keeps unpaid holds on a cancelled date from paying later', function (): void {
    $event = RideEvent::factory()->published()->create();
    $date = eventDate($event, days: 3);
    $order = app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 1));

    app(CancelEventOccurrence::class)->handle($date, SlotStatus::Cancelled, User::factory()->admin()->create(), 'Weather');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CarbonImmutable::now()->isBefore($date->starts_at))->toBeTrue();
});
