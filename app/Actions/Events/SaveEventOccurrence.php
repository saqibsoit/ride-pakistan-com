<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Booking\SlotLock;
use App\Enums\LessonClosure;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Events\PublicContentChanged;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adds or edits a date of an event (decided 2026-11-22). A date is a slot
 * carrying the event, one day or several; times are entered in Pakistan
 * time and stored in UTC. Editing follows the manual slot rules
 * (UpdateManualSlot): the times change only while nothing is booked, held
 * or ordered; capacity never below the seats taken (checked under the slot
 * row lock). Each change is logged ("slots") and the lesson closure is
 * applied again.
 */
final class SaveEventOccurrence
{
    public const MAX_CAPACITY = 200;

    public function __construct(
        private readonly SlotLock $lock,
        private readonly ApplyLessonClosure $closures,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function create(RideEvent $event, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $capacity, LessonClosure $closure, ?User $actor = null): Slot
    {
        $this->check($startsAt, $endsAt, $capacity);

        $slot = DB::transaction(function () use ($event, $startsAt, $endsAt, $capacity, $closure, $actor): Slot {
            $slot = new Slot(['starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc(), 'capacity' => $capacity, 'lesson_closure' => $closure]);
            $slot->source = SlotSource::Manual;
            $slot->ride_event_id = $event->id;
            $slot->save();

            activity('slots')
                ->performedOn($slot)
                ->causedBy($actor)
                ->event('event_date_created')
                ->withProperties(['event' => $event->slug, 'capacity' => $capacity, 'lesson_closure' => $closure->value])
                ->log('Date '.$slot->dateRangeLabel().' added to '.$event->title);

            return $slot;
        });

        $this->closures->handle($slot, $actor);
        // Dates show on cached pages (/experiences, the sitemap): refresh them.
        PublicContentChanged::dispatch();

        return $slot;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function update(Slot $slot, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $capacity, LessonClosure $closure, ?User $actor = null): Slot
    {
        $this->check($startsAt, $endsAt, $capacity);

        $slot = DB::transaction(function () use ($slot, $startsAt, $endsAt, $capacity, $closure, $actor): Slot {
            $locked = $this->lock->acquire($slot->id);
            $current = $locked->slot;

            if ($current->ride_event_id === null || $current->status === SlotStatus::Cancelled) {
                throw new InvalidArgumentException('Only dates of an event that aren’t cancelled can be edited.');
            }

            $timesChanged = ! $current->starts_at->equalTo($startsAt->utc()) || ! $current->ends_at->equalTo($endsAt->utc());

            if ($timesChanged && $current->isInUse()) {
                throw new InvalidArgumentException('People have booked or are paying for this date, so its times can’t change. Cancel it instead, or add another date.');
            }

            $taken = $locked->seatsBooked + $locked->seatsHeld;

            if ($capacity < $taken) {
                throw new InvalidArgumentException('This date already has '.$taken.' places taken, so its capacity can’t go below that.');
            }

            $before = ['starts_at' => $current->starts_at->toIso8601String(), 'ends_at' => $current->ends_at->toIso8601String(), 'capacity' => $current->capacity, 'lesson_closure' => $current->lesson_closure->value];
            $current->fill(['starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc(), 'capacity' => $capacity, 'lesson_closure' => $closure]);
            $current->save();

            activity('slots')
                ->performedOn($current)
                ->causedBy($actor)
                ->event('event_date_updated')
                ->withProperties(['before' => $before, 'capacity' => $capacity, 'lesson_closure' => $closure->value])
                ->log('Event date '.$current->dateRangeLabel().' updated');

            return $current;
        });

        $this->closures->handle($slot, $actor);
        // Dates show on cached pages (/experiences, the sitemap): refresh them.
        PublicContentChanged::dispatch();

        return $slot;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function check(CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $capacity): void
    {
        if (! $startsAt->isFuture()) {
            throw new InvalidArgumentException('The date must be in the future.');
        }

        if (! $endsAt->isAfter($startsAt)) {
            throw new InvalidArgumentException('The end must be after the start.');
        }

        if ($endsAt->diffInDays($startsAt, true) > 31) {
            throw new InvalidArgumentException('A date can last at most 31 days.');
        }

        if ($capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw new InvalidArgumentException('Capacity must be between 1 and '.self::MAX_CAPACITY.'.');
        }
    }
}
