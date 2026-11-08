<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Actions\Booking\SlotLock;
use App\Enums\SlotStatus;
use App\Exceptions\SlotNotCreated;
use App\Exceptions\SlotNotUpdated;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edits a future, open manual slot (milestone 10a) under the slot lock:
 *
 * - capacity: up freely, down to no less than the seats taken;
 * - start and end: only while nothing is booked, held or ordered on it;
 * - private-only: only while the slot is empty.
 *
 * Rule slots follow their schedule rule instead. Audited; the page cache
 * clears through SeatAvailabilityObserver.
 */
final class UpdateManualSlot
{
    public function __construct(private readonly SlotLock $lock) {}

    /**
     * @throws SlotNotUpdated
     * @throws SlotNotCreated
     */
    public function handle(Slot $slot, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $capacity, bool $privateOnly, ?User $actor = null): Slot
    {
        $startsAt = $startsAt->utc()->startOfMinute();
        $endsAt = $endsAt->utc()->startOfMinute();

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw SlotNotCreated::endsBeforeStart();
        }

        if ($capacity < CreateManualSlot::MIN_CAPACITY || $capacity > CreateManualSlot::MAX_CAPACITY) {
            throw SlotNotCreated::invalidCapacity(CreateManualSlot::MIN_CAPACITY, CreateManualSlot::MAX_CAPACITY);
        }

        return DB::transaction(function () use ($slot, $startsAt, $endsAt, $capacity, $privateOnly, $actor): Slot {
            $locked = $this->lock->acquire($slot->id);
            $current = $locked->slot;
            $now = CarbonImmutable::now();

            if (! $current->isManual()) {
                throw SlotNotUpdated::notManual();
            }

            if ($current->status !== SlotStatus::Open) {
                throw SlotNotUpdated::notOpen();
            }

            if (! $current->starts_at->isAfter($now)) {
                throw SlotNotUpdated::startedOrPast();
            }

            $timeChanged = ! $current->starts_at->equalTo($startsAt) || ! $current->ends_at->equalTo($endsAt);
            $inUse = $current->isInUse();

            if ($timeChanged) {
                if ($inUse) {
                    throw SlotNotUpdated::inUse('time');
                }

                if (! $startsAt->isAfter($now)) {
                    throw SlotNotCreated::notInFuture();
                }

                if (Slot::query()->where('starts_at', $startsAt)->whereKeyNot($current->id)->exists()) {
                    throw SlotNotCreated::startTaken($startsAt->setTimezone(Slot::displayTimezone())->format('D j M Y, H:i'));
                }
            }

            if ($privateOnly !== $current->private_only && ! $locked->isEmpty()) {
                throw SlotNotUpdated::inUse('private-only setting');
            }

            $taken = $locked->seatsBooked + $locked->seatsHeld;

            if ($capacity < $taken) {
                throw SlotNotUpdated::belowTaken($taken);
            }

            $before = [
                'starts_at' => $current->starts_at->toIso8601String(),
                'ends_at' => $current->ends_at->toIso8601String(),
                'capacity' => $current->capacity,
                'private_only' => $current->private_only,
            ];

            $current->fill([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'capacity' => $capacity,
                'private_only' => $privateOnly,
            ]);

            if (! $current->isDirty()) {
                return $current;
            }

            $current->save();

            activity('slots')
                ->performedOn($current)
                ->causedBy($actor)
                ->event('updated_manual')
                ->withProperties(['old' => $before, 'attributes' => [
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'capacity' => $capacity,
                    'private_only' => $privateOnly,
                ]])
                ->log('Manual slot changed: '.$current->label());

            return $current;
        });
    }
}
