<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\Rider;
use App\Models\Slot;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Package credits book at most `booking.package_sessions_per_week` [2]
 * sessions per rider per week (Monday to Sunday, Pakistan time, by the
 * session start; decided 2026-11-16). 0 switches the limit off.
 *
 * Counts the rider's package bookings (credit_pack_id set) that aren't
 * cancelled. Applies to customers booking with credits (waitlist claims
 * included), customers moving a credit booking, and a package's first
 * session when it is ordered. Admin bookings and admin moves, and moves
 * after a slot time change, don't check it.
 */
final class PackageWeeklyLimit
{
    public function __construct(private readonly Settings $settings) {}

    public function limit(): int
    {
        return max(0, $this->settings->int(SettingKey::PackageSessionsPerWeek) ?? 0);
    }

    /**
     * @param  list<int>  $riderIds  riders about to take a package session in $slot
     * @param  list<int>  $ignoreBookingIds  bookings being moved out of their slot
     *
     * @throws BookingRejected
     */
    public function assertAllows(array $riderIds, Slot $slot, array $ignoreBookingIds = []): void
    {
        $limit = $this->limit();

        if ($limit === 0 || $riderIds === []) {
            return;
        }

        [$from, $until] = self::week($slot->starts_at);

        $booked = Booking::query()
            ->whereIn('bookings.rider_id', $riderIds)
            ->whereNotNull('bookings.credit_pack_id')
            ->where('bookings.status', '!=', BookingStatus::Cancelled)
            ->when($ignoreBookingIds !== [], fn ($q) => $q->whereNotIn('bookings.id', $ignoreBookingIds))
            ->join('slots', 'slots.id', '=', 'bookings.slot_id')
            ->where('slots.starts_at', '>=', $from)
            ->where('slots.starts_at', '<', $until)
            ->selectRaw('bookings.rider_id, count(*) as sessions')
            ->groupBy('bookings.rider_id')
            ->pluck('sessions', 'bookings.rider_id');

        foreach ($riderIds as $riderId) {
            if ((int) ($booked[$riderId] ?? 0) >= $limit) {
                $name = Rider::query()->whereKey($riderId)->value('name');

                throw BookingRejected::weeklyLimit(is_string($name) ? $name : 'This rider', $limit, $from->setTimezone(Slot::displayTimezone()));
            }
        }
    }

    /**
     * The Pakistan week (Monday 00:00 to the next Monday) around a start, in UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function week(CarbonImmutable $startsAt): array
    {
        $monday = $startsAt->setTimezone(Slot::displayTimezone())->startOfWeek(CarbonImmutable::MONDAY);

        return [$monday->utc(), $monday->addWeek()->utc()];
    }
}
