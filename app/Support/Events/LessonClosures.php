<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Actions\Slots\SlotOverlap;
use App\Enums\LessonClosure;
use App\Enums\RideEventStatus;
use App\Enums\SlotStatus;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which lesson times an event occurrence closes (decided 2026-11-22):
 * "overlap" = lessons within the slot buffer of the occurrence, "day" =
 * every lesson on its Pakistan dates. Only occurrences of published events
 * that aren't cancelled close anything.
 */
final class LessonClosures
{
    public function __construct(private readonly SlotOverlap $overlap) {}

    /** The occurrence that closes a lesson at these times, if any. */
    public function closerFor(CarbonImmutable $startsAt, CarbonImmutable $endsAt): ?Slot
    {
        $buffer = $this->overlap->bufferMinutes();
        $tz = Slot::displayTimezone();

        // Widest window first (whole local days), then check the mode.
        $dayStart = $startsAt->setTimezone($tz)->startOfDay()->utc();
        $dayEnd = $endsAt->setTimezone($tz)->endOfDay()->utc();

        foreach ($this->closingOccurrences()
            ->where('slots.starts_at', '<=', $dayEnd->max($endsAt->addMinutes($buffer)))
            ->where('slots.ends_at', '>=', $dayStart->min($startsAt->subMinutes($buffer)))
            ->orderBy('slots.starts_at')
            ->get() as $occurrence) {
            if ($this->closes($occurrence, $startsAt, $endsAt)) {
                return $occurrence;
            }
        }

        return null;
    }

    /** Would $occurrence close a lesson at these times? */
    public function closes(Slot $occurrence, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return match ($occurrence->lesson_closure) {
            LessonClosure::None => false,
            LessonClosure::Overlap => $this->overlap->clashes($startsAt, $endsAt, $occurrence->starts_at, $occurrence->ends_at),
            LessonClosure::Day => $this->sharesADay($occurrence, $startsAt, $endsAt),
        };
    }

    /**
     * The lesson slots (open or closed, not cancelled, not started) that
     * $occurrence closes.
     *
     * @return Collection<int, Slot>
     */
    public function lessonsClosedBy(Slot $occurrence): Collection
    {
        if ($occurrence->lesson_closure === LessonClosure::None) {
            return new Collection;
        }

        $buffer = $this->overlap->bufferMinutes();
        $tz = Slot::displayTimezone();
        $from = $occurrence->lesson_closure === LessonClosure::Day
            ? $occurrence->starts_at->setTimezone($tz)->startOfDay()->utc()
            : $occurrence->starts_at->subMinutes($buffer);
        $until = $occurrence->lesson_closure === LessonClosure::Day
            ? $occurrence->ends_at->setTimezone($tz)->endOfDay()->utc()
            : $occurrence->ends_at->addMinutes($buffer);

        return Slot::query()
            ->lessons()
            ->where('status', '!=', SlotStatus::Cancelled)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Slot $lesson): bool => $this->closes($occurrence, $lesson->starts_at, $lesson->ends_at))
            ->values();
    }

    /**
     * Occurrences that close lessons: published event, not cancelled, a closure mode.
     *
     * @return Builder<Slot>
     */
    public function closingOccurrences(): Builder
    {
        return Slot::query()
            ->events()
            ->where('slots.status', '!=', SlotStatus::Cancelled)
            ->where('slots.lesson_closure', '!=', LessonClosure::None)
            ->whereHas('rideEvent', fn (Builder $q) => $q->where('status', RideEventStatus::Published));
    }

    private function sharesADay(Slot $occurrence, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $tz = Slot::displayTimezone();
        $occFirst = $occurrence->starts_at->setTimezone($tz)->format('Y-m-d');
        $occLast = $occurrence->ends_at->subSecond()->setTimezone($tz)->format('Y-m-d');
        $first = $startsAt->setTimezone($tz)->format('Y-m-d');
        $last = $endsAt->subSecond()->setTimezone($tz)->format('Y-m-d');

        return $first <= $occLast && $last >= $occFirst;
    }
}
