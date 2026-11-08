<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\OrderItem;
use App\Models\Slot;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The overbooking guard (PLAN §5): SELECT ... FOR UPDATE on the slot row,
 * then the slot's bookings and active holds read with locking reads too.
 *
 * Why locking reads: under MariaDB's REPEATABLE READ a plain SELECT reads
 * the transaction's snapshot, which can predate the moment we got the slot
 * lock, and miss a hold another transaction just committed. Locking reads
 * always see the latest committed rows. Every writer of bookings or holds
 * takes the slot lock first, so they queue one after another per slot.
 *
 * Must be called inside a transaction. Lock several slots in id order.
 */
final class SlotLock
{
    /**
     * @param  int|null  $ignoreOrderId  leave this order's own hold out of the count (fulfilment)
     * @param  list<int>  $ignoreBookingIds  leave these bookings out (moving them within the transaction)
     * @param  int|null  $claimFor  user id booking now: their own waitlist offer's seats count as free
     *                              (milestone 10d); the caller then claims the entries (ClaimWaitlistPlace)
     */
    public function acquire(int $slotId, ?int $ignoreOrderId = null, array $ignoreBookingIds = [], ?int $claimFor = null): LockedSlot
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SlotLock::acquire() must run inside a database transaction.');
        }

        $slot = Slot::query()->whereKey($slotId)->lockForUpdate()->firstOrFail();

        $bookings = Booking::query()
            ->where('slot_id', $slotId)
            ->whereIn('status', BookingStatus::seatOccupyingValues())
            ->when($ignoreBookingIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreBookingIds))
            ->lockForUpdate()
            ->get(['id', 'rider_id', 'is_private']);

        $ownEntries = $claimFor === null ? [] : WaitlistEntry::query()
            ->where('slot_id', $slotId)
            ->where('user_id', $claimFor)
            ->active()
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $holds = BookingHold::query()
            ->where('slot_id', $slotId)
            ->where('expires_at', '>', now())
            // Waitlist offers have no order: "order_id != x" alone would drop them.
            ->when($ignoreOrderId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('order_id')->orWhere('order_id', '!=', $ignoreOrderId)))
            ->when($ownEntries !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('waitlist_entry_id')->orWhereNotIn('waitlist_entry_id', $ownEntries)))
            ->lockForUpdate()
            ->get(['id', 'order_id', 'seats', 'is_private']);

        $holdOrderIds = $holds->pluck('order_id')->filter()->all();

        $heldRiderIds = $holdOrderIds === [] ? [] : OrderItem::query()
            ->where('slot_id', $slotId)
            ->whereIn('order_id', $holdOrderIds)
            ->whereNotNull('rider_id')
            ->sharedLock()
            ->pluck('rider_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return new LockedSlot(
            slot: $slot,
            seatsBooked: $bookings->count(),
            seatsHeld: (int) $holds->sum('seats'),
            privatelyTaken: $bookings->contains('is_private', true) || $holds->contains('is_private', true),
            riderIds: array_values(array_unique([
                ...$bookings->pluck('rider_id')->map(fn (mixed $id): int => (int) $id)->all(),
                ...$heldRiderIds,
            ])),
            waitlistEntryIds: array_values($ownEntries),
        );
    }
}
