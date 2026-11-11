<?php

declare(strict_types=1);

namespace App\Actions\PrivateRequests;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Horse;
use App\Models\Slot;
use Carbon\CarbonImmutable;

/**
 * Horse limit for a private request approval (prompt 15B, decided
 * 2026-11-11): on that Pakistan day, riders already booked plus the new
 * riders must not exceed the rides the working horses can give (the sum of
 * max_sessions_per_day of active, unretired horses without a rest or vet
 * day). Skipped while no horses are set up.
 */
final class HorseDayCapacity
{
    /** Rides the horses can give that day, or null when no horses are set up. */
    public function capacity(CarbonImmutable $startsAt): ?int
    {
        if (! Horse::query()->exists()) {
            return null;
        }

        $day = $startsAt->setTimezone(Slot::displayTimezone())->toDateString();

        return (int) Horse::query()
            ->working()
            ->whereDoesntHave('unavailabilities', fn ($q) => $q->whereDate('date', $day))
            ->sum('max_sessions_per_day');
    }

    /** Riders already in seat-occupying bookings that Pakistan day. */
    public function booked(CarbonImmutable $startsAt): int
    {
        $day = $startsAt->setTimezone(Slot::displayTimezone())->startOfDay();

        return Booking::query()
            ->whereIn('status', BookingStatus::seatOccupyingValues())
            ->whereHas('slot', fn ($q) => $q
                ->where('starts_at', '>=', $day->utc())
                ->where('starts_at', '<', $day->addDay()->utc()))
            ->count();
    }

    /** Why these extra riders don't fit, or null when they do. */
    public function problem(CarbonImmutable $startsAt, int $riders): ?string
    {
        $capacity = $this->capacity($startsAt);

        if ($capacity === null) {
            return null;
        }

        $booked = $this->booked($startsAt);

        if ($booked + $riders > $capacity) {
            return sprintf('Not enough horses that day: %d rides available, %d already booked, %d requested.', $capacity, $booked, $riders);
        }

        return null;
    }
}
