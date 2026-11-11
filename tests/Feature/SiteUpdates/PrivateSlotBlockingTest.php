<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CreateManualBooking;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Payments\ExtendTransferHold;
use App\Actions\Waitlist\JoinWaitlist;
use App\Actions\Waitlist\OfferWaitlistSeats;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;

/*
 * Prompt 14, item 6 (PLAN §2): a private session blocks the whole slot.
 * Once a private session is held or booked, no single, group,
 * package-credit, waitlist or admin booking can be added; and a private
 * session can't be booked on a slot that already has any booking or hold.
 * Every path goes through SlotLock / LockedSlot::assertRoomFor.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
    $this->privateProduct = Product::factory()->private(1_500_000)->create();
    $this->single = Product::factory()->create(['price_minor' => 1_000_000]);
});

/** @return array{0: User, 1: list<int>} */
function blockingCustomer(int $riders = 1): array
{
    $user = User::factory()->create();

    return [$user, array_values(array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys()))];
}

function blockingReason(callable $callback): ?string
{
    try {
        $callback();
    } catch (BookingRejected $e) {
        return $e->reason;
    }

    return null;
}

function blockingPack(User $user): CreditPack
{
    return CreditPack::factory()->for($user)->create(['credits_total' => 8, 'credits_used' => 0, 'expires_at' => now()->addWeeks(6)]);
}

/** A slot six days out (past every cutoff) that a private session has held (unpaid) or booked (paid), with 2 of 6 riders. */
function privatelyTakenSlot(Product $private, string $how): Slot
{
    $slot = nearSlot(6, 8, ['capacity' => 6]);
    [$user, $riders] = blockingCustomer(2);
    $order = app(CreateOrder::class)->handle($user, $private, $slot->id, $riders);

    if ($how === 'booked') {
        app(FulfilOrder::class)->handle($order);
        expect(Booking::query()->where('slot_id', $slot->id)->where('is_private', true)->count())->toBe(2)
            ->and(BookingHold::query()->where('slot_id', $slot->id)->count())->toBe(0);
    } else {
        expect(BookingHold::query()->where('slot_id', $slot->id)->sole()->seats)->toBe(6);
    }

    return $slot;
}

dataset('private states', ['held' => 'held', 'booked' => 'booked']);

it('refuses a single or group session on the slot', function (string $how): void {
    $slot = privatelyTakenSlot($this->privateProduct, $how);
    [$user, $riders] = blockingCustomer(1);
    [$group, $groupRiders] = blockingCustomer(3);

    expect(blockingReason(fn () => app(CreateOrder::class)->handle($user, $this->single, $slot->id, $riders)))->toBe(BookingRejected::SLOT_FULL)
        ->and(blockingReason(fn () => app(CreateOrder::class)->handle($group, $this->single, $slot->id, $groupRiders)))->toBe(BookingRejected::SLOT_FULL);
})->with('private states');

it('refuses a package’s first session on the slot', function (string $how): void {
    $slot = privatelyTakenSlot($this->privateProduct, $how);
    [$user, $riders] = blockingCustomer();
    $package = Product::factory()->package(8, 6_000_000, 42)->create();

    expect(blockingReason(fn () => app(CreateOrder::class)->handle($user, $package, $slot->id, $riders)))->toBe(BookingRejected::SLOT_FULL);
})->with('private states');

it('refuses package credits on the slot', function (string $how): void {
    $slot = privatelyTakenSlot($this->privateProduct, $how);
    [$user, $riders] = blockingCustomer();
    blockingPack($user);

    expect(blockingReason(fn () => app(BookWithCredit::class)->handle($user, $slot->id, $riders)))->toBe(BookingRejected::SLOT_FULL);
})->with('private states');

it('refuses moving a booking into the slot, for the customer and for an admin', function (string $how): void {
    $slot = privatelyTakenSlot($this->privateProduct, $how);
    [$user, $riders] = blockingCustomer();
    blockingPack($user);
    $booking = app(BookWithCredit::class)->handle($user, nearSlot(7, 8)->id, $riders)->sole();
    $admin = User::factory()->admin()->create();

    expect(blockingReason(fn () => app(RescheduleBooking::class)->handle($booking, $slot->id, $user)))->toBe(BookingRejected::SLOT_FULL)
        ->and(blockingReason(fn () => app(RescheduleBooking::class)->handle($booking, $slot->id, $admin, 'Customer asked', asAdmin: true)))->toBe(BookingRejected::SLOT_FULL)
        ->and($booking->refresh()->slot_id)->not->toBe($slot->id);
})->with('private states');

it('refuses an admin manual booking, with credit or at no charge', function (string $how): void {
    $slot = privatelyTakenSlot($this->privateProduct, $how);
    [$user, $riders] = blockingCustomer();
    $pack = blockingPack($user);
    $admin = User::factory()->admin()->create();
    $rider = Rider::query()->findOrFail($riders[0]);

    expect(blockingReason(fn () => app(CreateManualBooking::class)->handle($admin, $rider, $slot->id, reason: 'Friend of the trainer')))->toBe(BookingRejected::SLOT_FULL)
        ->and(blockingReason(fn () => app(CreateManualBooking::class)->handle($admin, $rider, $slot->id, $pack)))->toBe(BookingRejected::SLOT_FULL)
        ->and(Booking::query()->where('slot_id', $slot->id)->where('is_private', false)->count())->toBe(0);
})->with('private states');

it('refuses joining the waitlist of a privately taken slot', function (string $how): void {
    $slot = privatelyTakenSlot($this->privateProduct, $how);
    [$user] = blockingCustomer();

    expect(blockingReason(fn () => app(JoinWaitlist::class)->handle($user, $slot->id, 1)))->toBe(BookingRejected::BOOKED_PRIVATELY)
        ->and(WaitlistEntry::query()->where('slot_id', $slot->id)->count())->toBe(0);
})->with('private states');

it('makes no waitlist offer on a privately taken slot', function (string $how): void {
    $slot = privatelyTakenSlot($this->privateProduct, $how);
    $entry = WaitlistEntry::factory()->create(['slot_id' => $slot->id, 'seats' => 1]);

    expect(app(OfferWaitlistSeats::class)->handle($slot->id))->toBe(0)
        ->and(BookingHold::query()->where('waitlist_entry_id', $entry->id)->exists())->toBeFalse();
})->with('private states');

it('refuses a private session on a slot with any booking or hold', function (string $occupant): void {
    $slot = nearSlot(6, 8, ['capacity' => 6]);

    match ($occupant) {
        'confirmed booking' => Booking::factory()->create(['slot_id' => $slot->id]),
        'card hold' => BookingHold::factory()->create(['slot_id' => $slot->id, 'order_id' => Order::factory()->create(['status' => OrderStatus::PendingPayment])->id]),
        'bank transfer hold' => BookingHold::factory()->create(['slot_id' => $slot->id, 'expires_at' => now()->addHours(20), 'order_id' => Order::factory()->create(['status' => OrderStatus::AwaitingTransfer, 'payment_method' => PaymentMethod::BankTransfer])->id]),
        'waitlist offer' => BookingHold::factory()->create(['slot_id' => $slot->id, 'order_id' => null, 'waitlist_entry_id' => WaitlistEntry::factory()->create(['slot_id' => $slot->id])->id]),
    };

    [$user, $riders] = blockingCustomer();

    expect(blockingReason(fn () => app(CreateOrder::class)->handle($user, $this->privateProduct, $slot->id, $riders)))->toBe(BookingRejected::SLOT_NOT_EMPTY)
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(0);
})->with(['confirmed booking', 'card hold', 'bank transfer hold', 'waitlist offer']);

it('re-checks a private order whose hold lapsed before payment or an extension', function (): void {
    $slot = nearSlot(6, 8, ['capacity' => 6]);
    [$user, $riders] = blockingCustomer(2);
    $order = app(CreateOrder::class)->handle($user, $this->privateProduct, $slot->id, $riders);
    BookingHold::query()->where('order_id', $order->id)->update(['expires_at' => now()->subMinute()]);

    // Someone else books a seat while the private hold has lapsed.
    [$other, $otherRiders] = blockingCustomer();
    app(CreateOrder::class)->handle($other, $this->single, $slot->id, $otherRiders);

    expect(blockingReason(fn () => app(FulfilOrder::class)->handle($order)))->toBe(BookingRejected::SLOT_NOT_EMPTY)
        ->and(Booking::query()->where('order_id', $order->id)->count())->toBe(0);

    $order->forceFill(['status' => OrderStatus::AwaitingTransfer, 'payment_method' => PaymentMethod::BankTransfer, 'expires_at' => now()->subMinute()])->save();
    $admin = User::factory()->admin()->create();

    expect(blockingReason(fn () => app(ExtendTransferHold::class)->handle($order, $admin, CarbonImmutable::now()->addHours(12), 'Customer at the bank')))->toBe(BookingRejected::SLOT_NOT_EMPTY)
        ->and(BookingHold::query()->where('order_id', $order->id)->where('expires_at', '>', now())->exists())->toBeFalse();
});
