<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\SlotStatus;
use App\Events\SlotCancelled;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place a slot changes status: close, reopen or cancel. Cancelling
 * needs a reason, is final, and announces SlotCancelled after commit.
 */
final class ChangeSlotStatus
{
    /**
     * @throws InvalidStatusTransition
     */
    public function handle(Slot $slot, SlotStatus $to, ?User $actor = null, ?string $reason = null): Slot
    {
        $reason = OrderStateMachine::cleanReason($reason);

        if ($to->requiresReason() && $reason === null) {
            throw InvalidStatusTransition::reasonRequired('Slot', $to->value);
        }

        $slot = DB::transaction(function () use ($slot, $to, $actor, $reason): Slot {
            $locked = Slot::query()->whereKey($slot->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between('Slot', $from->value, $to->value);
            }

            $locked->status = $to;

            if ($to === SlotStatus::Cancelled) {
                $locked->cancel_reason = $reason;
                $locked->cancelled_at = now()->toImmutable();
                $locked->cancelled_by = $actor?->id;
            }

            $locked->save();

            activity('slots')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'reason' => $reason])
                ->log(sprintf('Slot %s: %s → %s', $locked->label(), $from->label(), $to->label()));

            return $locked;
        });

        if ($to === SlotStatus::Cancelled) {
            SlotCancelled::dispatch($slot);
        }

        return $slot;
    }
}
