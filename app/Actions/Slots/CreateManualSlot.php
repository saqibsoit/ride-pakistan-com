<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Enums\SlotSource;
use App\Exceptions\SlotNotCreated;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Adds a one-off slot outside the schedule rules (any date and time, its
 * own capacity), on any day, optionally private-only. Manual slots are never changed or removed by
 * GenerateSlots; they change status through ChangeSlotStatus like any slot.
 */
final class CreateManualSlot
{
    public const MIN_CAPACITY = 1;

    public const MAX_CAPACITY = 50;

    /**
     * @throws SlotNotCreated
     */
    public function handle(CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $capacity, ?User $actor = null, bool $privateOnly = false, ?User $trainer = null): Slot
    {
        $startsAt = $startsAt->utc()->startOfMinute();
        $endsAt = $endsAt->utc()->startOfMinute();

        if ($startsAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw SlotNotCreated::notInFuture();
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw SlotNotCreated::endsBeforeStart();
        }

        if ($trainer !== null && ! $trainer->isTrainer()) {
            throw SlotNotCreated::notATrainer();
        }

        if ($capacity < self::MIN_CAPACITY || $capacity > self::MAX_CAPACITY) {
            throw SlotNotCreated::invalidCapacity(self::MIN_CAPACITY, self::MAX_CAPACITY);
        }

        try {
            return DB::transaction(function () use ($startsAt, $endsAt, $capacity, $actor, $privateOnly, $trainer): Slot {
                $taken = Slot::query()->where('starts_at', $startsAt)->lockForUpdate()->exists();

                if ($taken) {
                    throw SlotNotCreated::startTaken(self::label($startsAt));
                }

                $slot = new Slot([
                    'schedule_rule_id' => null,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'capacity' => $capacity,
                    'private_only' => $privateOnly,
                    'trainer_id' => $trainer?->id,
                ]);
                $slot->source = SlotSource::Manual;
                $slot->save();

                activity('slots')
                    ->performedOn($slot)
                    ->causedBy($actor)
                    ->event('created_manual')
                    ->withProperties(['starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(), 'capacity' => $capacity, 'private_only' => $privateOnly, 'trainer_id' => $trainer?->id])
                    ->log('Manual slot added: '.$slot->label());

                return $slot;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request created a slot at the same start in between.
            throw SlotNotCreated::startTaken(self::label($startsAt));
        }
    }

    private static function label(CarbonImmutable $startsAt): string
    {
        return $startsAt->setTimezone(Slot::displayTimezone())->format('D j M Y, H:i');
    }
}
