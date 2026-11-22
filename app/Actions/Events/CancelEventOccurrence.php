<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Slots\ChangeSlotStatus;
use App\Enums\SlotStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Slot;
use App\Models\User;

/**
 * Close, reopen or cancel an event date (decided 2026-11-22) through
 * ChangeSlotStatus, then apply the lesson closure again (a cancelled date
 * reopens the lessons it closed). Cancelling needs a reason; paid
 * customers then choose a full refund or another date (CancelBookingsForSlot
 * → EventDateCancelled).
 */
final class CancelEventOccurrence
{
    public function __construct(
        private readonly ChangeSlotStatus $status,
        private readonly ApplyLessonClosure $closures,
    ) {}

    /**
     * @throws InvalidStatusTransition
     */
    public function handle(Slot $occurrence, SlotStatus $to, User $actor, ?string $reason = null): Slot
    {
        if (! $occurrence->isEvent()) {
            throw new InvalidStatusTransition('Not an event date.');
        }

        $slot = $this->status->handle($occurrence, $to, $actor, $reason);
        $this->closures->handle($slot, $actor);

        return $slot;
    }
}
