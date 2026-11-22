<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Live slots with seats left for the public pages and the booking flow:
 * rule and manual slots alike, from the slots table (not schedule rules).
 * Read-only and approximate by design (cached pages are refreshed after
 * every seat change); HoldSeats decides under the slot lock.
 */
final class Availability
{
    public const TYPE_GROUP = 'group';

    public const TYPE_PRIVATE = 'private';

    public function __construct(private readonly Settings $settings) {}

    /**
     * Upcoming open slots from $from (local midnight) to $to, with seat counts.
     * Private: only slots nobody has booked or held.
     *
     * @return Collection<int, Slot>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to, ?string $type = null): Collection
    {
        // Lesson slots only: event occurrences have their own pages (decided 2026-11-22).
        $slots = Slot::query()
            ->withSeatCounts()
            ->lessons()
            ->whereIn('slots.status', [SlotStatus::Open, SlotStatus::Closed])
            ->where('slots.starts_at', '>', $this->bookableAfter())
            ->where('slots.starts_at', '>=', $from->utc())
            ->where('slots.starts_at', '<', $to->utc())
            ->orderBy('slots.starts_at')
            ->get();

        return match ($type) {
            self::TYPE_PRIVATE => $slots->filter(fn (Slot $slot): bool => $slot->status === SlotStatus::Open && $slot->isEmpty())->values(),
            self::TYPE_GROUP => $slots->reject(fn (Slot $slot): bool => $slot->private_only || $slot->isPrivatelyBooked())->values(),
            default => $slots,
        };
    }

    /**
     * Monday to Sunday (local) of the week starting $monday, keyed by local date.
     *
     * @return array<string, Collection<int, Slot>>
     */
    public function week(CarbonImmutable $monday, ?string $type = null): array
    {
        $slots = $this->between($monday, $monday->addWeek(), $type);
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $monday->addDays($i)->format('Y-m-d');
            $days[$date] = $slots->filter(fn (Slot $slot): bool => $slot->localStartsAt()->format('Y-m-d') === $date)->values();
        }

        return $days;
    }

    /**
     * Coming up (home page): today and the next $days - 1 days (local).
     * Sessions run daily since 2026-11-16, so this replaced "This weekend".
     *
     * @return Collection<int, Slot>
     */
    public function comingUp(int $days = 3): Collection
    {
        $today = SessionTimes::now()->startOfDay();

        return $this->between($today, $today->addDays($days));
    }

    /**
     * Next bookable slots with room (for the product page widget and the
     * booking flow).
     *
     * @return Collection<int, Slot>
     */
    public function upcoming(int $limit = 8, ?string $type = null, int $weeks = 4): Collection
    {
        $from = SessionTimes::now()->startOfDay();

        return $this->between($from, $from->addWeeks($weeks), $type)
            ->filter(fn (Slot $slot): bool => $slot->status === SlotStatus::Open && $slot->seatsFree() > 0)
            ->take($limit)
            ->values();
    }

    /** One slot with seat counts, if it can still be booked. */
    public function find(int $id): ?Slot
    {
        $slot = Slot::query()->withSeatCounts()->lessons()->find($id);

        return $slot !== null
            && $slot->status === SlotStatus::Open
            && $slot->starts_at->isAfter($this->bookableAfter())
            ? $slot
            : null;
    }

    public function bookableAfter(): CarbonImmutable
    {
        return CarbonImmutable::now()->addHours($this->settings->int(SettingKey::MinNoticeHours) ?? 0);
    }
}
