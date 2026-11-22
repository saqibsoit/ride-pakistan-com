<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Booking;
use App\Models\OrderItem;
use App\Models\RideEventTier;
use Illuminate\Support\Collection;

/**
 * Seats per price tier, in the shape LockedSlot::assertTierRoom() and
 * HoldSeats take (decided 2026-11-22).
 */
final class EventTiers
{
    /**
     * @param  iterable<OrderItem>  $items
     * @return array{seats: array<int, int>, caps: array<int, int|null>, names: array<int, string>}
     */
    public static function forItems(iterable $items): array
    {
        $seats = [];

        foreach ($items as $item) {
            if ($item->ride_event_tier_id !== null) {
                $seats[$item->ride_event_tier_id] = ($seats[$item->ride_event_tier_id] ?? 0) + 1;
            }
        }

        return self::withCaps($seats);
    }

    /**
     * @param  Collection<int, Booking>|iterable<Booking>  $bookings
     * @return array{seats: array<int, int>, caps: array<int, int|null>, names: array<int, string>}
     */
    public static function forBookings(iterable $bookings): array
    {
        $itemIds = [];

        foreach ($bookings as $booking) {
            if ($booking->order_item_id !== null) {
                $itemIds[] = $booking->order_item_id;
            }
        }

        return self::forItems($itemIds === [] ? [] : OrderItem::query()->whereKey($itemIds)->get());
    }

    /**
     * @param  array<int, int>  $seats
     * @return array{seats: array<int, int>, caps: array<int, int|null>, names: array<int, string>}
     */
    public static function withCaps(array $seats): array
    {
        $tiers = $seats === [] ? collect() : RideEventTier::query()->whereKey(array_keys($seats))->get(['id', 'name', 'seats_per_occurrence']);

        return [
            'seats' => $seats,
            'caps' => $tiers->mapWithKeys(fn (RideEventTier $t): array => [$t->id => $t->seats_per_occurrence])->all(),
            'names' => $tiers->mapWithKeys(fn (RideEventTier $t): array => [$t->id => $t->name])->all(),
        ];
    }
}
