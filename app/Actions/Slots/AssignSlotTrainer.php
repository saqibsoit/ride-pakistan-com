<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Models\Slot;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sets or clears the trainer of one slot (milestone 10b, admins only).
 * Overrides the rule's default for this slot; ApplyRuleTrainer leaves it
 * alone from then on. Logged.
 */
final class AssignSlotTrainer
{
    public function handle(Slot $slot, ?User $trainer, User $actor): Slot
    {
        if (! $actor->can('assignTrainer', $slot)) {
            throw new AuthorizationException('Only an admin can change who runs a session.');
        }

        if ($trainer !== null && ! $trainer->isTrainer()) {
            throw new InvalidArgumentException('Only a trainer account can run a session.');
        }

        return DB::transaction(function () use ($slot, $trainer, $actor): Slot {
            $locked = Slot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();
            $from = $locked->trainer_id;

            if ($from === $trainer?->id) {
                return $slot;
            }

            $locked->trainer_id = $trainer?->id;
            $locked->save();

            activity('slots')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('trainer_assigned')
                ->withProperties(['from' => $from, 'to' => $trainer?->id])
                ->log(sprintf('Trainer for %s: %s', $locked->label(), $trainer?->displayName() ?? 'none'));

            $slot->setRawAttributes($locked->getAttributes(), true);

            return $slot;
        });
    }
}
