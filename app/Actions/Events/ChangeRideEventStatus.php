<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\RideEventStatus;
use App\Enums\SlotStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\RideEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place an event changes status (decided 2026-11-22): draft ⇄
 * published, either → archived, archived → draft. Publishing needs a price
 * tier and a date to come. Archiving takes it off the site for good
 * (unfeatured); an event with bookings is archived, never deleted. Every
 * change is logged ("content") and clears the page cache (the model's
 * observer), and the lesson closures of its dates follow (published events
 * close lessons; others don't).
 */
final class ChangeRideEventStatus
{
    public function __construct(private readonly ApplyLessonClosure $closures) {}

    /**
     * @throws InvalidStatusTransition
     */
    public function handle(RideEvent $event, RideEventStatus $to, User $actor): RideEvent
    {
        $event = DB::transaction(function () use ($event, $to, $actor): RideEvent {
            $locked = RideEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between('Event', $from->value, $to->value);
            }

            if ($to === RideEventStatus::Published) {
                if (! $locked->tiers()->exists()) {
                    throw new InvalidStatusTransition('Add a price before publishing.');
                }

                if (! $locked->occurrences()->where('status', '!=', SlotStatus::Cancelled)->where('starts_at', '>', now())->exists()) {
                    throw new InvalidStatusTransition('Add a date to come before publishing.');
                }
            }

            $locked->status = $to;
            $locked->archived_at = $to === RideEventStatus::Archived ? now()->toImmutable() : null;

            if ($to === RideEventStatus::Archived) {
                $locked->is_featured = false;
            }

            $locked->save();

            activity('content')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties(['from' => $from->value, 'to' => $to->value])
                ->log('Event '.$locked->title.': '.$from->getLabel().' → '.$to->getLabel());

            return $locked;
        });

        $this->closures->forEvent($event, $actor);

        return $event;
    }
}
