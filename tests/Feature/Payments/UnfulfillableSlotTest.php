<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Payments\ApproveBankTransfer;
use App\Actions\Payments\ChooseBankTransfer;
use App\Actions\Payments\CreateStripeCheckout;
use App\Actions\Payments\SubmitBankTransfer;
use App\Enums\BankTransferStatus;
use App\Enums\OrderStatus;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Filament\Pages\VerifyTransfers;
use App\Models\BankTransfer;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\OrderNotFulfilled;
use App\Support\TransferProofs;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/*
 * A payment that arrives after its seat hold ran out is fulfilled only if
 * the slot is still open, has room and isn't blocked by a private session
 * (FulfilOrder, under the slot row lock). Otherwise no booking is made:
 * a card payment is refunded automatically, a bank transfer is flagged
 * "Needs review", and the customer is emailed once (OrderNotFulfilled).
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
    Storage::fake(TransferProofs::DISK);
    Notification::fake();
});

/** A one-rider order on a slot five days out whose hold has run out. */
function lapsedOrder(int $hourOffset = 0): Order
{
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $slot = Slot::factory()->at(CarbonImmutable::now()->addDays(5)->addHours($hourOffset))->create(['capacity' => 6]);
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(['price_minor' => 1_000_000]), $slot->id, [$rider->id]);

    return $order;
}

function lapseHolds(Order $order): void
{
    BookingHold::query()->where('order_id', $order->id)->update(['expires_at' => now()->subMinute()]);
}

function orderSlot(Order $order): Slot
{
    return Slot::query()->findOrFail((int) $order->items()->value('slot_id'));
}

/** What made the slot unbookable while the hold had run out. */
function makeUnbookable(Slot $slot, string $how): void
{
    match ($how) {
        'closed' => $slot->forceFill(['status' => SlotStatus::Closed])->save(),
        'cancelled' => $slot->forceFill(['status' => SlotStatus::Cancelled, 'cancel_reason' => 'Weather', 'cancelled_at' => now()])->save(),
        'private' => Booking::factory()->create(['slot_id' => $slot->id, 'is_private' => true]),
        'full' => Booking::factory()->count($slot->capacity)->create(['slot_id' => $slot->id]),
    };
}

/** The signed checkout.session.completed payload; post it twice to mimic a Stripe retry (same event id). */
function stripeCompletedPayload(Order $order): string
{
    return (string) json_encode(FakeStripeGateway::event('checkout.session.completed', [
        'id' => $order->stripe_checkout_session_id ?? 'cs_test_x',
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'client_reference_id' => (string) $order->id,
        'metadata' => ['reference' => $order->reference, 'order_id' => (string) $order->id],
        'amount_total' => $order->amount_minor,
        'currency' => 'pkr',
        'payment_intent' => 'pi_test_'.$order->id,
    ]));
}

function postStripePayload(TestCase $test, string $payload): void
{
    $test->call('POST', '/webhooks/stripe', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => FakeStripeGateway::sign($payload),
    ], content: $payload)->assertOk();
}

function lapsedTransfer(): BankTransfer
{
    $order = lapsedOrder();
    app(ChooseBankTransfer::class)->handle($order);

    $image = imagecreatetruecolor(60, 40);
    ob_start();
    imagejpeg($image);
    $path = (string) tempnam(sys_get_temp_dir(), 'proof');
    file_put_contents($path, (string) ob_get_clean());

    $transfer = app(SubmitBankTransfer::class)->handle($order->refresh(), new UploadedFile($path, 'proof.jpg', null, null, true), 'TXN-77');
    lapseHolds($order);

    return $transfer;
}

dataset('unbookable', ['closed', 'cancelled', 'private']);

it('refuses to fulfil a lapsed hold on a closed, cancelled or privately booked slot', function (string $how): void {
    $order = lapsedOrder();
    lapseHolds($order);
    makeUnbookable(orderSlot($order), $how);

    $reason = null;
    try {
        app(FulfilOrder::class)->handle($order);
    } catch (BookingRejected $e) {
        $reason = $e->reason;
    }

    expect($reason)->toBe($how === 'private' ? BookingRejected::SLOT_FULL : BookingRejected::SLOT_NOT_BOOKABLE)
        ->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(Booking::query()->where('order_id', $order->id)->exists())->toBeFalse();
})->with('unbookable');

it('still fulfils a lapsed hold when the slot is open with room', function (): void {
    $order = lapsedOrder();
    lapseHolds($order);

    app(FulfilOrder::class)->handle($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Booking::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('keeps a live hold when the slot was closed afterwards (closing stops new bookings only)', function (): void {
    $order = lapsedOrder();
    makeUnbookable(orderSlot($order), 'closed');

    app(FulfilOrder::class)->handle($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('refunds a late card payment automatically and emails the customer', function (string $how): void {
    $order = lapsedOrder();
    app(CreateStripeCheckout::class)->handle($order);
    $order->refresh();
    lapseHolds($order);
    makeUnbookable(orderSlot($order), $how);

    $payload = stripeCompletedPayload($order);
    postStripePayload($this, $payload);
    postStripePayload($this, $payload); // Stripe retries the same event: no second refund or email

    $order->refresh();
    expect(Booking::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and(fakeStripe()->refunds)->toHaveCount(1)
        ->and(fakeStripe()->refunds[0]['params']['amount'])->toBe($order->amount_minor)
        ->and($order->amount_refunded_minor)->toBe($order->amount_minor)
        ->and($order->meta['needs_review'] ?? null)->toContain('Refunded automatically');

    Notification::assertSentToTimes($order->user, OrderNotFulfilled::class, 1);
    Notification::assertSentTo($order->user, OrderNotFulfilled::class, fn (OrderNotFulfilled $n): bool => $n->refunded && $n->order->is($order));
})->with('unbookable');

it('flags a bank transfer for review and emails the customer once instead of approving it', function (string $how): void {
    $transfer = lapsedTransfer();
    makeUnbookable(orderSlot($transfer->order), $how);
    $admin = User::factory()->admin()->withMfa()->create();

    expect(fn () => app(ApproveBankTransfer::class)->handle($transfer, $admin))->toThrow(BookingRejected::class)
        ->and(fn () => app(ApproveBankTransfer::class)->handle($transfer, $admin))->toThrow(BookingRejected::class);

    $order = $transfer->order->refresh();
    expect($transfer->refresh()->status)->toBe(BankTransferStatus::Submitted)
        ->and($order->status)->toBe(OrderStatus::AwaitingVerification)
        ->and(Payment::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and(Booking::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and($order->meta['needs_review'] ?? null)->toContain('Bank transfer received but the booking could not be confirmed');

    Notification::assertSentToTimes($order->user, OrderNotFulfilled::class, 1);
    Notification::assertSentTo($order->user, OrderNotFulfilled::class, fn (OrderNotFulfilled $n): bool => ! $n->refunded);
})->with('unbookable');

it('tells the admin the order is flagged and the customer emailed', function (): void {
    $transfer = lapsedTransfer();
    makeUnbookable(orderSlot($transfer->order), 'closed');
    $this->actingAs(User::factory()->admin()->withMfa()->create());

    Livewire::test(VerifyTransfers::class)
        ->callAction(TestAction::make('approve')->table($transfer->order))
        ->assertNotified('Not approved');

    expect($transfer->order->refresh()->meta['needs_review'] ?? null)->not->toBeNull();
    Notification::assertSentTo($transfer->order->user, OrderNotFulfilled::class);
});

it('renders both versions of the customer email', function (): void {
    $order = lapsedOrder();

    $refunded = (string) (new OrderNotFulfilled($order, refunded: true))->toMail($order->user)->render();
    $review = (string) (new OrderNotFulfilled($order, refunded: false))->toMail($order->user)->render();

    expect($refunded)->toContain($order->reference)->toContain('refunded the full amount')
        ->and($review)->toContain($order->reference)->toContain('move you to another session or refund you')->not->toContain('refunded the full amount');
});
