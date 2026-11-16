<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Actions\Booking\SlotLock;
use App\Actions\Orders\OrderStateMachine;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Events\SlotCancelled;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only place a slot changes status: close, reopen or cancel. Cancelling
 * needs a reason and announces SlotCancelled after commit. A cancelled slot
 * stays cancelled, with one exception: an empty future cancelled slot is
 * reused when a new slot is wanted at its start (reopenForPrivateRequest()
 * and reopenAsNewSlot() below).
 */
final class ChangeSlotStatus
{
    public function __construct(private readonly SlotLock $lock) {}

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

    /**
     * Reuses a cancelled slot for an approved private session request
     * (prompt 15B follow-up): it reopens as a private-only manual slot with
     * the request's end, capacity and trainer. See reopenCancelled().
     *
     * Must run inside the caller's transaction (it takes the slot lock).
     *
     * @throws InvalidStatusTransition
     */
    public function reopenForPrivateRequest(Slot $slot, CarbonImmutable $endsAt, int $capacity, ?User $trainer, User $actor, string $requestReference): Slot
    {
        return $this->reopenCancelled(
            $slot, $endsAt, $capacity, true, $trainer, $actor,
            event: 'reopened_for_private_request',
            context: ['request' => $requestReference],
            message: 'Cancelled slot %s reopened as private-only for request '.$requestReference,
        );
    }

    /**
     * Reuses a cancelled slot for a new manual slot at the same start
     * (CreateManualSlot: the Slots screen and the bulk schedule editor).
     *
     * Must run inside the caller's transaction (it takes the slot lock).
     *
     * @throws InvalidStatusTransition
     */
    public function reopenAsNewSlot(Slot $slot, CarbonImmutable $endsAt, int $capacity, bool $privateOnly, ?User $trainer, ?User $actor): Slot
    {
        return $this->reopenCancelled(
            $slot, $endsAt, $capacity, $privateOnly, $trainer, $actor,
            event: 'reopened_as_new_slot',
            context: [],
            message: 'Cancelled slot %s reopened as a new '.($privateOnly ? 'private-only ' : '').'slot',
        );
    }

    /**
     * Slot starts are unique, so a cancelled slot would otherwise block its
     * start time for good. Allowed only for a future cancelled slot with
     * nothing on it any more (no booking that takes a seat, no active hold).
     * It reopens as an open manual slot with the given end, capacity,
     * private-only flag and trainer, detached from any schedule rule; its
     * old cancellation details are cleared (kept in the audit log). Open
     * and closed slots are never taken over.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidStatusTransition
     */
    private function reopenCancelled(Slot $slot, CarbonImmutable $endsAt, int $capacity, bool $privateOnly, ?User $trainer, ?User $actor, string $event, array $context, string $message): Slot
    {
        $locked = $this->lock->acquire($slot->id);
        $current = $locked->slot;

        if ($current->status !== SlotStatus::Cancelled) {
            throw InvalidStatusTransition::between('Slot', $current->status->value, SlotStatus::Open->value);
        }

        if (! $current->starts_at->isAfter(CarbonImmutable::now())) {
            throw new InvalidStatusTransition('This cancelled slot has already started.');
        }

        if ($locked->seatsBooked > 0 || $locked->seatsHeld > 0 || $locked->privatelyTaken) {
            throw new InvalidStatusTransition('This cancelled slot still has bookings or holds on it.');
        }

        $before = [
            'status' => $current->status->value,
            'ends_at' => $current->ends_at->toIso8601String(),
            'capacity' => $current->capacity,
            'private_only' => $current->private_only,
            'trainer_id' => $current->trainer_id,
            'source' => $current->source->value,
            'cancel_reason' => $current->cancel_reason,
            'cancelled_at' => $current->cancelled_at?->toIso8601String(),
            'cancelled_by' => $current->cancelled_by,
        ];

        $current->status = SlotStatus::Open;
        $current->source = SlotSource::Manual; // never handed back to a schedule rule
        $current->schedule_rule_id = null;
        $current->ends_at = $endsAt->utc()->startOfMinute();
        $current->capacity = $capacity;
        $current->private_only = $privateOnly;
        $current->trainer_id = $trainer?->id;
        $current->cancel_reason = null;
        $current->cancelled_at = null;
        $current->cancelled_by = null;
        $current->save();

        activity('slots')
            ->performedOn($current)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(['old' => $before, ...$context])
            ->log(sprintf($message, $current->label()));

        $slot->setRawAttributes($current->getAttributes(), true);

        return $slot;
    }
}
