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
        $slots = Slot::query()
            ->withSeatCounts()
            ->whereIn('slots.status', [SlotStatus::Open, SlotStatus::Closed])
            ->where('slots.starts_at', '>', $this->bookableAfter())
            ->where('slots.starts_at', '>=', $from->utc())
            ->where('slots.starts_at', '<', $to->utc())
            ->orderBy('slots.starts_at')
            ->get();

        return match ($type) {
            self::TYPE_PRIVATE => $slots->filter(fn (Slot $slot): bool => $slot->status === SlotStatus::Open && $slot->isEmpty())->values(),
            self::TYPE_GROUP => $slots->reject(fn (Slot $slot): bool => $slot->isPrivatelyBooked())->values(),
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
     * The coming weekend: today and tomorrow if it is already the weekend,
     * otherwise next Saturday and Sunday (local).
     *
     * @return Collection<int, Slot>
     */
    public function weekend(): Collection
    {
        $today = SessionTimes::now()->startOfDay();
        $saturday = $today->isSunday() ? $today->subDay() : ($today->isSaturday() ? $today : $today->next(CarbonImmutable::SATURDAY));

        return $this->between($saturday, $saturday->addDays(2));
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
        $slot = Slot::query()->withSeatCounts()->find($id);

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
