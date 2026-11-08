<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CreateManualBooking;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\ExpireCreditPacks;
use App\Actions\Booking\ExtendCreditPack;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Orders\OrderStateMachine;
use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\CreditReason;
use App\Enums\OrderStatus;
use App\Exceptions\BookingRejected;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonInterface;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{0: User, 1: list<int>}
 */
function bookingCustomer(int $riders = 1): array
{
    $user = User::factory()->create();

    return [$user, array_values(array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys()))];
}

/** A paid pack for $user. */
function packFor(User $user, int $total = 8, int $used = 0, ?CarbonInterface $expiresAt = null): CreditPack
{
    return CreditPack::factory()->create([
        'order_id' => Order::factory()->for($user)->status(OrderStatus::Paid),
        'credits_total' => $total,
        'credits_used' => $used,
        'expires_at' => $expiresAt ?? now()->addWeeks(6),
    ]);
}

function reasonOf(callable $callback): ?string
{
    try {
        $callback();
    } catch (BookingRejected $e) {
        return $e->reason;
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| FulfilOrder
|--------------------------------------------------------------------------
*/

it('turns a paid single-session order into bookings with unique tickets', function (): void {
    [$user, $riders] = bookingCustomer(2);
    $slot = nearSlot();
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders);

    app(FulfilOrder::class)->handle($order, context: ['source' => 'test']);

    $bookings = Booking::query()->where('order_id', $order->id)->get();

    expect($order->fresh()?->status)->toBe(OrderStatus::Paid)
        ->and($order->fresh()?->paid_at)->not->toBeNull()
        ->and($bookings)->toHaveCount(2)
        ->and($bookings->pluck('ticket_code')->unique())->toHaveCount(2)
        ->and($bookings->first()?->ticket_code)->toMatch('/^[A-HJ-NP-Z2-9]{12}$/')
        ->and($bookings->every(fn (Booking $b): bool => $b->status === BookingStatus::Confirmed && ! $b->is_private))->toBeTrue()
        ->and(BookingHold::query()->count())->toBe(0)
        ->and($slot->fresh()?->seatsFree())->toBe(4)
        ->and(Activity::query()->where('event', 'fulfilled')->exists())->toBeTrue();
});

it('creates no duplicates when FulfilOrder runs twice', function (): void {
    [$user, $riders] = bookingCustomer(2);
    $order = app(CreateOrder::class)->handle($user, Product::factory()->package()->create(), nearSlot()->id, $riders);

    app(FulfilOrder::class)->handle($order);
    app(FulfilOrder::class)->handle(Order::query()->findOrFail($order->id));
    app(FulfilOrder::class)->handle($order);

    expect(Booking::query()->count())->toBe(2)
        ->and(CreditPack::query()->count())->toBe(1)
        ->and(CreditLedgerEntry::query()->count())->toBe(3)
        ->and(Activity::query()->where('event', 'fulfilled')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'status_changed')->count())->toBe(1);
});

it('keeps a fulfilled private session blocking the whole slot', function (): void {
    [$user, $riders] = bookingCustomer(2);
    $slot = nearSlot(attributes: ['capacity' => 6]);
    $order = app(CreateOrder::class)->handle($user, Product::factory()->private()->create(), $slot->id, $riders);

    app(FulfilOrder::class)->handle($order);

    expect(Booking::query()->where('is_private', true)->count())->toBe(2)
        ->and(Slot::query()->withSeatCounts()->findOrFail($slot->id)->seatsFree())->toBe(0);

    [$other, $otherRiders] = bookingCustomer();
    expect(reasonOf(fn () => app(CreateOrder::class)->handle($other, Product::factory()->create(), $slot->id, $otherRiders)))
        ->toBe(BookingRejected::SLOT_FULL);
});

it('grants a package pack valid from payment, with ledger entries and the first sessions', function (): void {
    [$user, $riders] = bookingCustomer(2);
    $package = Product::factory()->package(8, 6_000_000, 42)->create();
    $order = app(CreateOrder::class)->handle($user, $package, nearSlot()->id, $riders);

    $this->travel(10)->minutes();
    app(FulfilOrder::class)->handle($order);

    $pack = CreditPack::query()->sole();

    expect($pack->user_id)->toBe($user->id)
        ->and($pack->credits_total)->toBe(8)
        ->and($pack->credits_used)->toBe(2)
        ->and($pack->valid_from->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($pack->expires_at->toDateTimeString())->toBe(now()->addDays(42)->toDateTimeString())
        ->and(Booking::query()->where('credit_pack_id', $pack->id)->count())->toBe(2)
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::Granted)->sole()->delta)->toBe(8)
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::Used)->sum('delta'))->toEqual(-2);
});

it('grants a package without a first session', function (): void {
    [$user] = bookingCustomer();
    $order = app(CreateOrder::class)->handle($user, Product::factory()->package(16, 10_000_000, 70)->create(), null, []);

    app(FulfilOrder::class)->handle($order);

    expect(CreditPack::query()->sole()->creditsRemaining())->toBe(16)
        ->and(Booking::query()->count())->toBe(0);
});

it('takes the seat again if the hold ran out but the slot still has room', function (): void {
    [$user, $riders] = bookingCustomer();
    $slot = nearSlot();
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders);

    BookingHold::query()->update(['expires_at' => now()->subMinute()]);

    app(FulfilOrder::class)->handle($order);

    expect(Booking::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('refuses to fulfil when the hold ran out and the seat was taken', function (): void {
    [$user, $riders] = bookingCustomer();
    $slot = nearSlot(attributes: ['capacity' => 1]);
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders);

    BookingHold::query()->update(['expires_at' => now()->subMinute()]);
    Booking::factory()->create(['slot_id' => $slot->id]);

    expect(reasonOf(fn () => app(FulfilOrder::class)->handle($order)))->toBe(BookingRejected::SLOT_FULL)
        ->and($order->fresh()?->status)->toBe(OrderStatus::PendingPayment)
        ->and(Booking::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('refuses to fulfil expired or cancelled orders', function (OrderStatus $status): void {
    $order = Order::factory()->status($status)->create();

    expect(reasonOf(fn () => app(FulfilOrder::class)->handle($order)))->toBe(BookingRejected::ORDER_NOT_FULFILLABLE);
})->with([OrderStatus::Expired, OrderStatus::Cancelled, OrderStatus::Rejected, OrderStatus::AwaitingTransfer]);

it('fulfils a bank transfer order once it is awaiting verification', function (): void {
    [$user, $riders] = bookingCustomer();
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(), nearSlot()->id, $riders);
    app(OrderStateMachine::class)->transition($order, OrderStatus::AwaitingTransfer);
    app(OrderStateMachine::class)->transition($order, OrderStatus::AwaitingVerification);

    app(FulfilOrder::class)->handle($order, User::factory()->admin()->create());

    expect($order->fresh()?->status)->toBe(OrderStatus::Paid)->and(Booking::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| BookWithCredit
|--------------------------------------------------------------------------
*/

it('books with credits, one per rider, and logs it', function (): void {
    [$user, $riders] = bookingCustomer(2);
    $pack = packFor($user, 8, 6);
    $slot = nearSlot();

    $bookings = app(BookWithCredit::class)->handle($user, $slot->id, $riders);

    $pack->refresh();
    expect($bookings)->toHaveCount(2)
        ->and($pack->credits_used)->toBe(8)
        ->and($pack->status)->toBe(CreditPackStatus::Exhausted)
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::Used)->count())->toBe(2)
        ->and($slot->fresh()?->seatsFree())->toBe(4);
});

it('cannot book with an expired pack', function (): void {
    [$user, $riders] = bookingCustomer();
    packFor($user, expiresAt: now()->subDay());

    expect(reasonOf(fn () => app(BookWithCredit::class)->handle($user, nearSlot()->id, $riders)))->toBe(BookingRejected::NO_CREDITS);
});

it('cannot book a session after the pack expires', function (): void {
    [$user, $riders] = bookingCustomer();
    packFor($user, expiresAt: now()->addDays(2));

    expect(reasonOf(fn () => app(BookWithCredit::class)->handle($user, nearSlot(5)->id, $riders)))->toBe(BookingRejected::NO_CREDITS);
});

it('cannot use more credits than are left', function (): void {
    [$user, $riders] = bookingCustomer(3);
    packFor($user, 8, 6);

    $e = null;

    try {
        app(BookWithCredit::class)->handle($user, nearSlot()->id, $riders);
    } catch (BookingRejected $e) {
    }

    expect($e?->reason)->toBe(BookingRejected::NO_CREDITS)
        ->and($e?->getMessage())->toBe('You have 2 credits valid for that date.')
        ->and(Booking::query()->count())->toBe(0);
});

it('spreads riders across packs, soonest expiry first', function (): void {
    [$user, $riders] = bookingCustomer(2);
    $later = packFor($user, 8, 0, now()->addWeeks(8));
    $sooner = packFor($user, 8, 7, now()->addWeeks(2));

    app(BookWithCredit::class)->handle($user, nearSlot()->id, $riders);

    expect($sooner->fresh()?->credits_used)->toBe(8)
        ->and($later->fresh()?->credits_used)->toBe(1);
});

it('respects capacity and private sessions when booking with credits', function (): void {
    [$user, $riders] = bookingCustomer();
    packFor($user);
    $full = nearSlot(attributes: ['capacity' => 1]);
    Booking::factory()->create(['slot_id' => $full->id]);
    $private = nearSlot(4);
    Booking::factory()->create(['slot_id' => $private->id, 'is_private' => true]);

    expect(reasonOf(fn () => app(BookWithCredit::class)->handle($user, $full->id, $riders)))->toBe(BookingRejected::SLOT_FULL)
        ->and(reasonOf(fn () => app(BookWithCredit::class)->handle($user, $private->id, $riders)))->toBe(BookingRejected::SLOT_FULL);
});

it('books a rider only once per slot with credits', function (): void {
    [$user, $riders] = bookingCustomer();
    packFor($user);
    $slot = nearSlot();
    app(BookWithCredit::class)->handle($user, $slot->id, $riders);

    expect(reasonOf(fn () => app(BookWithCredit::class)->handle($user, $slot->id, $riders)))->toBe(BookingRejected::RIDER_ALREADY_BOOKED);
});

/*
|--------------------------------------------------------------------------
| CancelBooking
|--------------------------------------------------------------------------
*/

it('returns the credit when a credit booking is cancelled before the cutoff', function (): void {
    [$user, $riders] = bookingCustomer();
    $pack = packFor($user, 8, 7);
    $booking = app(BookWithCredit::class)->handle($user, nearSlot(5)->id, $riders)->sole();

    $returned = app(CancelBooking::class)->handle($booking, $user);

    $pack->refresh();
    expect($returned)->toBeTrue()
        ->and($booking->fresh()?->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->fresh()?->cancelled_at)->not->toBeNull()
        ->and($pack->credits_used)->toBe(7)
        ->and($pack->status)->toBe(CreditPackStatus::Active)
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::Returned)->count())->toBe(1);
});

it('keeps the credit used when cancelled after the cutoff', function (): void {
    [$user, $riders] = bookingCustomer();
    $pack = packFor($user);
    $booking = app(BookWithCredit::class)->handle($user, nearSlot(1)->id, $riders)->sole();

    $returned = app(CancelBooking::class)->handle($booking, $user);

    expect($returned)->toBeFalse()
        ->and($booking->fresh()?->status)->toBe(BookingStatus::Cancelled)
        ->and($pack->fresh()?->credits_used)->toBe(1)
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::Returned)->count())->toBe(0);
});

it('applies the cutoff at exactly 48 hours', function (): void {
    [$user, $riders] = bookingCustomer();
    packFor($user);
    $slot = nearSlot(5);
    $booking = app(BookWithCredit::class)->handle($user, $slot->id, $riders)->sole();

    $this->travelTo($slot->starts_at->subHours(48));
    expect(app(CancelBooking::class)->handle($booking, $user))->toBeTrue();
});

it('does not return a credit to an expired pack', function (): void {
    [$user, $riders] = bookingCustomer();
    $pack = packFor($user, expiresAt: now()->addDays(6));
    $booking = app(BookWithCredit::class)->handle($user, nearSlot(5)->id, $riders)->sole();

    $this->travel(3)->days(); // still before the cutoff, but…
    $pack->forceFill(['expires_at' => now()->subMinute()])->save(); // …the pack has run out

    expect(app(CancelBooking::class)->handle($booking, $user))->toBeFalse();
});

it('does not let customers cancel paid bookings or other people’s bookings', function (): void {
    [$user, $riders] = bookingCustomer();
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(), nearSlot()->id, $riders);
    app(FulfilOrder::class)->handle($order);
    $paid = Booking::query()->sole();

    [$other, $otherRiders] = bookingCustomer();
    packFor($other);
    $theirs = app(BookWithCredit::class)->handle($other, nearSlot(4)->id, $otherRiders)->sole();

    expect(reasonOf(fn () => app(CancelBooking::class)->handle($paid, $user)))->toBe(BookingRejected::NOT_ALLOWED)
        ->and(reasonOf(fn () => app(CancelBooking::class)->handle($theirs, $user)))->toBe(BookingRejected::NOT_ALLOWED);
});

it('lets an admin cancel any booking with a reason and choose to return the credit', function (): void {
    [$user, $riders] = bookingCustomer();
    $pack = packFor($user);
    $booking = app(BookWithCredit::class)->handle($user, nearSlot(1)->id, $riders)->sole();
    $admin = User::factory()->admin()->create();

    expect(reasonOf(fn () => app(CancelBooking::class)->handle($booking, $admin, asAdmin: true)))->toBe(BookingRejected::NOT_ALLOWED);

    $returned = app(CancelBooking::class)->handle($booking, $admin, 'Heavy rain', asAdmin: true, returnCredit: true);

    expect($returned)->toBeTrue()->and($pack->fresh()?->credits_used)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| RescheduleBooking
|--------------------------------------------------------------------------
*/

it('moves a credit booking before the cutoff and keeps the ticket', function (): void {
    [$user, $riders] = bookingCustomer();
    packFor($user);
    $from = nearSlot(5);
    $to = nearSlot(6);
    $booking = app(BookWithCredit::class)->handle($user, $from->id, $riders)->sole();
    $ticket = $booking->ticket_code;

    app(RescheduleBooking::class)->handle($booking, $to->id, $user);

    expect($booking->fresh()?->slot_id)->toBe($to->id)
        ->and($booking->fresh()?->ticket_code)->toBe($ticket)
        ->and($from->fresh()?->seatsFree())->toBe(6)
        ->and($to->fresh()?->seatsFree())->toBe(5)
        ->and(Activity::query()->where('event', 'rescheduled')->exists())->toBeTrue();
});

it('refuses customer moves after the cutoff, to a full slot or past the pack expiry', function (): void {
    [$user, $riders] = bookingCustomer();
    packFor($user, expiresAt: now()->addDays(7));
    $soon = app(BookWithCredit::class)->handle($user, nearSlot(1)->id, $riders)->sole();

    expect(reasonOf(fn () => app(RescheduleBooking::class)->handle($soon, nearSlot(4)->id, $user)))->toBe(BookingRejected::PAST_CUTOFF);

    [$user2, $riders2] = bookingCustomer();
    packFor($user2, expiresAt: now()->addDays(7));
    $later = app(BookWithCredit::class)->handle($user2, nearSlot(5)->id, $riders2)->sole();
    $full = nearSlot(6, attributes: ['capacity' => 1]);
    Booking::factory()->create(['slot_id' => $full->id]);

    expect(reasonOf(fn () => app(RescheduleBooking::class)->handle($later, $full->id, $user2)))->toBe(BookingRejected::SLOT_FULL)
        ->and(reasonOf(fn () => app(RescheduleBooking::class)->handle($later, nearSlot(9)->id, $user2)))->toBe(BookingRejected::NO_CREDITS);
});

it('moves a private session as a group to an empty slot, admin only', function (): void {
    [$user, $riders] = bookingCustomer(2);
    $order = app(CreateOrder::class)->handle($user, Product::factory()->private()->create(), nearSlot()->id, $riders);
    app(FulfilOrder::class)->handle($order);
    $booking = Booking::query()->first();
    $admin = User::factory()->admin()->create();

    $busy = nearSlot(4);
    Booking::factory()->create(['slot_id' => $busy->id]);
    $empty = nearSlot(5);

    expect(reasonOf(fn () => app(RescheduleBooking::class)->handle($booking, $empty->id, $user)))->toBe(BookingRejected::NOT_ALLOWED)
        ->and(reasonOf(fn () => app(RescheduleBooking::class)->handle($booking, $busy->id, $admin, 'Trainer request', asAdmin: true)))->toBe(BookingRejected::SLOT_NOT_EMPTY);

    $moved = app(RescheduleBooking::class)->handle($booking, $empty->id, $admin, 'Trainer request', asAdmin: true);

    expect($moved)->toHaveCount(2)
        ->and(Booking::query()->where('slot_id', $empty->id)->count())->toBe(2)
        ->and(Slot::query()->withSeatCounts()->findOrFail($empty->id)->seatsFree())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Status, manual bookings, extensions, expiry
|--------------------------------------------------------------------------
*/

it('marks attendance only after the session starts and validates transitions', function (): void {
    $slot = nearSlot(1);
    $booking = Booking::factory()->create(['slot_id' => $slot->id]);
    $admin = User::factory()->admin()->create();

    expect(reasonOf(fn () => app(ChangeBookingStatus::class)->handle($booking, BookingStatus::Attended, $admin)))->toBe(BookingRejected::NOT_ALLOWED);

    $this->travelTo($slot->starts_at->addMinutes(5));
    app(ChangeBookingStatus::class)->handle($booking, BookingStatus::Attended, $admin);
    app(ChangeBookingStatus::class)->handle($booking, BookingStatus::NoShow, $admin, 'Checked in the wrong rider');

    expect($booking->fresh()?->status)->toBe(BookingStatus::NoShow)
        ->and(fn () => app(ChangeBookingStatus::class)->handle($booking, BookingStatus::Confirmed, $admin))->toThrow(InvalidStatusTransition::class);
});

it('adds a manual booking with a credit or as no-charge with a reason', function (): void {
    [$user, $riders] = bookingCustomer();
    $pack = packFor($user);
    $admin = User::factory()->admin()->create();
    $rider = Rider::query()->findOrFail($riders[0]);

    $withCredit = app(CreateManualBooking::class)->handle($admin, $rider, nearSlot()->id, $pack);
    expect($withCredit->credit_pack_id)->toBe($pack->id)->and($pack->fresh()?->credits_used)->toBe(1);

    expect(reasonOf(fn () => app(CreateManualBooking::class)->handle($admin, $rider, nearSlot(4)->id)))->toBe(BookingRejected::NOT_ALLOWED);

    $free = app(CreateManualBooking::class)->handle($admin, $rider, nearSlot(5)->id, reason: 'Trial for a returning rider');

    expect($free->order_id)->toBeNull()
        ->and($free->credit_pack_id)->toBeNull()
        ->and(Activity::query()->where('event', 'manual_booking')->sole()->properties?->get('reason'))->toBe('Trial for a returning rider')
        ->and(reasonOf(fn () => app(CreateManualBooking::class)->handle(User::factory()->staff()->create(), $rider, nearSlot(6)->id, reason: 'x')))->toBe(BookingRejected::NOT_ALLOWED);
});

it('extends a pack with a reason and revives an expired one', function (): void {
    [$user] = bookingCustomer();
    $pack = packFor($user, 8, 3, now()->subDay());
    $pack->forceFill(['status' => CreditPackStatus::Expired])->save();
    $admin = User::factory()->admin()->create();

    expect(reasonOf(fn () => app(ExtendCreditPack::class)->handle($pack, now()->addWeek()->toImmutable(), $admin, '  ')))->toBe(BookingRejected::NOT_ALLOWED);

    app(ExtendCreditPack::class)->handle($pack, now()->addWeeks(2)->toImmutable(), $admin, 'Injury, doctor’s note');

    $pack->refresh();
    expect($pack->status)->toBe(CreditPackStatus::Active)
        ->and($pack->isUsable())->toBeTrue()
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::Adjusted)->sole()->note)->toContain('Injury')
        ->and(Activity::query()->where('event', 'extended')->exists())->toBeTrue();
});

it('expires packs past their date and writes off the unused credits', function (): void {
    [$user] = bookingCustomer();
    $old = packFor($user, 8, 5, now()->subHour());
    $current = packFor($user);

    $this->artisan('credits:expire')->expectsOutput('Expired 1 credit pack.')->assertSuccessful();

    expect($old->fresh()?->status)->toBe(CreditPackStatus::Expired)
        ->and($current->fresh()?->status)->toBe(CreditPackStatus::Active)
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::Expired)->sole()->delta)->toBe(-3)
        ->and(app(ExpireCreditPacks::class)->handle())->toBe(0);
});
