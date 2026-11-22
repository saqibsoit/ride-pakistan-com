<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Slots\ChangeSlotStatus;
use App\Enums\BookingStatus;
use App\Enums\LessonClosure;
use App\Enums\RideEventStatus;
use App\Enums\SlotStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Booking;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\LessonBookingsToMove;
use App\Support\Events\LessonClosures;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Collection;

/**
 * Closes the regular lesson slots an event occurrence covers (decided
 * 2026-11-22): overlapping ones (with the slot buffer) or every lesson on
 * its day(s), per the occurrence's setting. Run when an occurrence is
 * saved, cancelled or reopened, and when its event is published,
 * unpublished or archived.
 *
 * - Open lesson slots in range are closed through ChangeSlotStatus (audit
 *   log), marked as closed for this occurrence.
 * - Bookings are never cancelled. Confirmed lesson bookings on those slots
 *   are returned (and shown on the event's admin page) for an admin to
 *   move; every admin gets one email per new set of clashes.
 * - Slots this occurrence closed that are no longer in range (the date
 *   moved, the mode changed, the date or event was cancelled, unpublished
 *   or archived) reopen, unless another occurrence still closes them.
 *   Slots closed by hand are left alone.
 */
final class ApplyLessonClosure
{
    public function __construct(
        private readonly LessonClosures $closures,
        private readonly ChangeSlotStatus $status,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @return Collection<int, Booking> lesson bookings on closed slots, for an admin to move
     */
    public function handle(Slot $occurrence, ?User $actor = null): Collection
    {
        $occurrence->refresh();
        $event = RideEvent::query()->find($occurrence->ride_event_id);
        $active = $event !== null
            && $event->status === RideEventStatus::Published
            && $occurrence->status !== SlotStatus::Cancelled
            && $occurrence->lesson_closure !== LessonClosure::None;

        $targets = $active ? $this->closures->lessonsClosedBy($occurrence) : new Collection;
        $targetIds = $targets->modelKeys();

        // Reopen what this occurrence closed and no longer covers.
        foreach (Slot::query()->where('closed_for_slot_id', $occurrence->id)->whereNotIn('id', $targetIds === [] ? [0] : $targetIds)->get() as $slot) {
            $other = $this->closures->closerFor($slot->starts_at, $slot->ends_at);

            if ($other !== null && $other->id !== $occurrence->id) {
                $slot->closed_for_slot_id = $other->id;
                $slot->save();

                continue;
            }

            $slot->closed_for_slot_id = null;
            $slot->save();

            if ($slot->status === SlotStatus::Closed && $slot->starts_at->isFuture()) {
                $this->changeStatus($slot, SlotStatus::Open, $actor);
            }
        }

        foreach ($targets as $slot) {
            if ($slot->status === SlotStatus::Open) {
                $this->changeStatus($slot, SlotStatus::Closed, $actor);
                $slot->refresh();
                $slot->closed_for_slot_id = $occurrence->id;
                $slot->save();
            }
        }

        $clashes = self::clashesFor($occurrence);

        if ($clashes->isNotEmpty()) {
            $key = 'lesson_clashes:'.$occurrence->id.':'.substr(hash('sha256', implode(',', $clashes->modelKeys())), 0, 16);

            User::query()
                ->where('role', UserRole::Admin)
                ->whereNotNull('email')
                ->each(fn (User $admin) => $this->notifier->send($admin, new LessonBookingsToMove($occurrence, $clashes), $key.':'.$admin->id));
        }

        return $clashes;
    }

    /** Every occurrence of the event (publishing, unpublishing, archiving). */
    public function forEvent(RideEvent $event, ?User $actor = null): void
    {
        foreach ($event->occurrences()->where('starts_at', '>', now())->get() as $occurrence) {
            $this->handle($occurrence, $actor);
        }
    }

    /**
     * Confirmed lesson bookings on the slots this occurrence closed.
     *
     * @return Collection<int, Booking>
     */
    public static function clashesFor(Slot $occurrence): Collection
    {
        return Booking::query()
            ->with(['slot', 'rider', 'user'])
            ->where('status', BookingStatus::Confirmed)
            ->whereIn('slot_id', Slot::query()->where('closed_for_slot_id', $occurrence->id)->select('id'))
            ->orderBy('slot_id')
            ->orderBy('id')
            ->get();
    }

    private function changeStatus(Slot $slot, SlotStatus $to, ?User $actor): void
    {
        try {
            $this->status->handle($slot, $to, $actor);
        } catch (InvalidStatusTransition) {
            // Changed meanwhile (e.g. cancelled): leave it as it is.
        }
    }
}
