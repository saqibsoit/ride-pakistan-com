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
     * @param  array<int, int>  $tierSeats  event occurrences: seats booked or held per price tier id
     */
    public function __construct(
        public readonly Slot $slot,
        public readonly int $seatsBooked,
        public readonly int $seatsHeld,
        public readonly bool $privatelyTaken,
        public readonly array $riderIds,
        public readonly array $waitlistEntryIds = [],
        public readonly array $tierSeats = [],
    ) {}

    /**
     * A lesson slot, not an event occurrence: lesson products, credits,
     * moves and manual bookings never take event seats (decided 2026-11-22).
     *
     * @throws BookingRejected
     */
    public function assertLesson(): void
    {
        if ($this->slot->ride_event_id !== null) {
            throw BookingRejected::slotNotBookable();
        }
    }

    /**
     * An occurrence of this event.
     *
     * @throws BookingRejected
     */
    public function assertOccurrenceOf(int $rideEventId): void
    {
        if ($this->slot->ride_event_id !== $rideEventId) {
            throw BookingRejected::slotNotBookable();
        }
    }

    /**
     * Room in each price tier that has its own cap, on top of the
     * occurrence's capacity (assertRoomFor).
     *
     * @param  array<int, int>  $seats  seats wanted per tier id
     * @param  array<int, int|null>  $caps  seats_per_occurrence per tier id (null = no cap)
     * @param  array<int, string>  $names  tier names, for the message
     *
     * @throws BookingRejected
     */
    public function assertTierRoom(array $seats, array $caps, array $names = []): void
    {
        foreach ($seats as $tierId => $count) {
            $cap = $caps[$tierId] ?? null;

            if ($cap === null || $count <= 0) {
                continue;
            }

            $free = max(0, $cap - ($this->tierSeats[$tierId] ?? 0));

            if ($count > $free) {
                throw BookingRejected::tierFull($names[$tierId] ?? 'of those', $free);
            }
        }
    }

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
