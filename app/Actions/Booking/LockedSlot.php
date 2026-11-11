<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Models\Rider;
use App\Models\Slot;
use Carbon\CarbonImmutable;

/**
 * A slot row locked FOR UPDATE with its seat usage read under that lock
 * (SlotLock::acquire). Every seat decision is made from one of these, so
 * two transactions can never both take the last seat.
 */
final class LockedSlot
{
    /**
     * @param  list<int>  $riderIds  riders already in a seat-occupying booking or an active hold
     * @param  list<int>  $waitlistEntryIds  the booking customer's active waitlist entries (SlotLock $claimFor)
     */
    public function __construct(
        public readonly Slot $slot,
        public readonly int $seatsBooked,
        public readonly int $seatsHeld,
        public readonly bool $privatelyTaken,
        public readonly array $riderIds,
        public readonly array $waitlistEntryIds = [],
    ) {}

    public function free(): int
    {
        if ($this->privatelyTaken) {
            return 0;
        }

        return max(0, $this->slot->capacity - $this->seatsBooked - $this->seatsHeld);
    }

    public function isEmpty(): bool
    {
        return ! $this->privatelyTaken && $this->seatsBooked === 0 && $this->seatsHeld === 0;
    }

    /**
     * Open and far enough in the future to book.
     *
     * @throws BookingRejected
     */
    public function assertBookable(int $minNoticeHours = 0, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        if ($this->slot->status !== SlotStatus::Open || ! $this->slot->starts_at->isAfter($now->addHours(max(0, $minNoticeHours)))) {
            throw BookingRejected::slotNotBookable();
        }
    }

    /**
     * Still taking bookings: open, not closed or cancelled. Unlike
     * assertBookable() it ignores the start time, for a payment that
     * arrives after the hold ran out (FulfilOrder).
     *
     * @throws BookingRejected
     */
    public function assertOpen(): void
    {
        if ($this->slot->status !== SlotStatus::Open) {
            throw BookingRejected::slotNotBookable();
        }
    }

    /**
     * Room for these riders: a private session needs the slot empty; a
     * group booking needs one free seat per rider. No rider twice.
     *
     * @param  list<int>  $riderIds
     *
     * @throws BookingRejected
     */
    public function assertRoomFor(array $riderIds, bool $private): void
    {
        $taken = array_values(array_intersect($riderIds, $this->riderIds));

        if ($taken !== []) {
            throw BookingRejected::riderAlreadyBooked(Rider::query()->find($taken[0])->name ?? 'A rider');
        }

        // Private-only slots (milestone 10a) take private sessions only.
        if (! $private && $this->slot->private_only) {
            throw BookingRejected::privateOnly();
        }

        if ($private) {
            if (! $this->isEmpty()) {
                throw BookingRejected::slotNotEmpty();
            }

            if (count($riderIds) > $this->slot->capacity) {
                throw BookingRejected::tooManyRiders($this->slot->capacity);
            }

            return;
        }

        if (count($riderIds) > $this->free()) {
            throw BookingRejected::slotFull($this->free());
        }
    }
}
