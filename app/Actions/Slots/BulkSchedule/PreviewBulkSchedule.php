<?php

declare(strict_types=1);

namespace App\Actions\Slots\BulkSchedule;

use App\Actions\Slots\CreateManualSlot;
use App\Actions\Slots\SlotOverlap;
use App\Enums\BulkScheduleOperation;
use App\Enums\SlotStatus;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Works out what the bulk schedule editor would do (prompt 15A), without
 * saving anything. ApplyBulkSchedule runs the same checks again under the
 * slot locks, for the rows the admin confirmed.
 *
 * - Create: a new slot per matching date. Skipped if it starts in the
 *   past, a slot already starts then, or it comes within the buffer of
 *   another session (SlotOverlap) or of a slot created in the same run.
 * - Update: open matching slots. Skipped when the new capacity is below
 *   the seats taken (booked + held), or the new time is in the past, taken,
 *   or too close to another session. A slot with an active hold (unpaid
 *   order, transfer waiting, waitlist offer) keeps its time; its capacity
 *   and trainer still change.
 * - Close: open matching slots. Cancel: open or closed matching slots.
 */
final class PreviewBulkSchedule
{
    public function __construct(private readonly SlotOverlap $overlap) {}

    /**
     * @throws InvalidArgumentException
     */
    public function handle(BulkScheduleCriteria $criteria): BulkSchedulePreview
    {
        if ($criteria->capacity !== null && ($criteria->capacity < CreateManualSlot::MIN_CAPACITY || $criteria->capacity > CreateManualSlot::MAX_CAPACITY)) {
            throw new InvalidArgumentException(sprintf('Capacity must be between %d and %d riders.', CreateManualSlot::MIN_CAPACITY, CreateManualSlot::MAX_CAPACITY));
        }

        if ($criteria->trainerId !== null && ! (User::query()->find($criteria->trainerId)?->isTrainer() ?? false)) {
            throw new InvalidArgumentException('Only a trainer account can run a session.');
        }

        $items = $criteria->operation === BulkScheduleOperation::Create
            ? $this->creates($criteria)
            : $this->changes($criteria);

        usort($items, fn (BulkScheduleItem $a, BulkScheduleItem $b): int => $a->startsAt <=> $b->startsAt);

        return new BulkSchedulePreview($items);
    }

    /**
     * The open, future slots the criteria match (for update and close; cancel also takes closed ones).
     *
     * @return Collection<int, Slot>
     */
    public function matching(BulkScheduleCriteria $criteria): Collection
    {
        $statuses = $criteria->operation === BulkScheduleOperation::Cancel
            ? [SlotStatus::Open, SlotStatus::Closed]
            : [SlotStatus::Open];

        return Slot::query()
            ->withSeatCounts()
            ->with('trainer')
            ->where('slots.starts_at', '>', CarbonImmutable::now())
            ->where('slots.starts_at', '>=', $criteria->from->utc())
            ->where('slots.starts_at', '<', $criteria->until->addDay()->utc())
            ->whereIn('slots.status', $statuses)
            ->when($criteria->type !== null, fn ($q) => $q->where('slots.private_only', $criteria->type === 'private'))
            ->orderBy('slots.starts_at')
            ->get()
            ->filter(fn (Slot $slot): bool => in_array($slot->localStartsAt()->dayOfWeek, $criteria->days, true)
                && ($criteria->matchStart === null || $slot->localStartsAt()->format('H:i') === $criteria->matchStart))
            ->values()
            ->toBase();
    }

    /**
     * @return list<BulkScheduleItem>
     */
    private function creates(BulkScheduleCriteria $criteria): array
    {
        $now = CarbonImmutable::now();
        $items = [];
        /** @var list<array{0: CarbonImmutable, 1: CarbonImmutable}> $planned */
        $planned = [];
        $capacity = (int) $criteria->capacity;
        $note = $criteria->type === 'private' ? 'Private only' : null;

        foreach ($criteria->dates() as $date) {
            $start = BulkScheduleCriteria::at($date, (string) $criteria->startTime);
            $end = BulkScheduleCriteria::at($date, (string) $criteria->endTime);

            $skip = function (string $reason) use (&$items, $start, $end, $capacity): void {
                $items[] = new BulkScheduleItem(BulkScheduleItem::SKIP, null, $start, $end, $capacity, note: $reason);
            };

            if (! $start->isAfter($now)) {
                $skip('In the past');

                continue;
            }

            if (Slot::query()->where('starts_at', $start)->exists()) {
                $skip('A slot already starts at this time');

                continue;
            }

            $clash = $this->overlap->firstClash($start, $end);

            if ($clash !== null) {
                $skip($this->clashNote($clash->label()));

                continue;
            }

            foreach ($planned as [$pStart, $pEnd]) {
                if ($this->overlap->clashes($start, $end, $pStart, $pEnd)) {
                    $skip($this->clashNote(BulkScheduleItem::range($pStart, $pEnd).' (new)'));

                    continue 2;
                }
            }

            $planned[] = [$start, $end];
            $items[] = new BulkScheduleItem(BulkScheduleItem::CREATE, null, $start, $end, $capacity, newTrainerId: $criteria->trainerId, note: $note);
        }

        return $items;
    }

    /**
     * @return list<BulkScheduleItem>
     */
    private function changes(BulkScheduleCriteria $criteria): array
    {
        $items = [];
        /** @var list<array{0: CarbonImmutable, 1: CarbonImmutable}> $planned */
        $planned = [];

        foreach ($this->matching($criteria) as $slot) {
            $booked = $slot->seatsBooked();
            $held = $slot->seatsHeld();
            $base = fn (string $kind, ?string $note = null): BulkScheduleItem => new BulkScheduleItem(
                $kind, $slot->id, $slot->starts_at, $slot->ends_at, $slot->capacity,
                trainerId: $slot->trainer_id, seatsBooked: $booked, seatsHeld: $held, note: $note,
            );

            if ($criteria->operation === BulkScheduleOperation::Close) {
                $items[] = $base(BulkScheduleItem::CLOSE, $booked > 0 ? 'Bookings stay; nobody new can book' : null);

                continue;
            }

            if ($criteria->operation === BulkScheduleOperation::Cancel) {
                $items[] = $base(BulkScheduleItem::CANCEL, $booked > 0 ? 'Bookings cancelled, one email per customer' : null);

                continue;
            }

            $item = $this->planUpdate($criteria, $slot, $booked, $held, $planned);

            if ($item->kind === BulkScheduleItem::UPDATE && $item->changesTime() && $item->newStartsAt !== null && $item->newEndsAt !== null) {
                $planned[] = [$item->newStartsAt, $item->newEndsAt];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * One slot's update, checked the same way ApplyBulkSchedule checks it
     * under the lock.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $planned  new times of earlier slots in this run
     */
    public function planUpdate(BulkScheduleCriteria $criteria, Slot $slot, int $booked, int $held, array $planned = [], bool $lock = false): BulkScheduleItem
    {
        $skip = fn (string $note): BulkScheduleItem => new BulkScheduleItem(
            BulkScheduleItem::SKIP, $slot->id, $slot->starts_at, $slot->ends_at, $slot->capacity,
            trainerId: $slot->trainer_id, seatsBooked: $booked, seatsHeld: $held, note: $note,
        );

        $taken = $booked + $held;
        $newCapacity = $criteria->capacity !== null && $criteria->capacity !== $slot->capacity ? $criteria->capacity : null;

        if ($newCapacity !== null && $newCapacity < $taken) {
            return $skip(sprintf('%d %s taken: capacity can’t go below that', $taken, $taken === 1 ? 'seat' : 'seats'));
        }

        $newStart = null;
        $newEnd = null;
        $notes = [];

        if ($criteria->startTime !== null && $criteria->endTime !== null) {
            $localDate = $slot->localStartsAt()->startOfDay();
            $start = BulkScheduleCriteria::at($localDate, $criteria->startTime);
            $end = BulkScheduleCriteria::at($localDate, $criteria->endTime);

            if (! $start->equalTo($slot->starts_at) || ! $end->equalTo($slot->ends_at)) {
                if ($held > 0) {
                    $notes[] = 'Time not changed: it has a hold or an unpaid order';
                } elseif (! $start->isAfter(CarbonImmutable::now())) {
                    return $skip('The new time is in the past');
                } elseif (! $start->equalTo($slot->starts_at) && Slot::query()->where('starts_at', $start)->whereKeyNot($slot->id)->exists()) {
                    return $skip('Another slot already starts at the new time');
                } else {
                    $clash = $this->overlap->firstClash($start, $end, [$slot->id], $lock);

                    if ($clash !== null) {
                        return $skip($this->clashNote($clash->label()));
                    }

                    foreach ($planned as [$pStart, $pEnd]) {
                        if ($this->overlap->clashes($start, $end, $pStart, $pEnd)) {
                            return $skip($this->clashNote(BulkScheduleItem::range($pStart, $pEnd).' (moved)'));
                        }
                    }

                    $newStart = $start;
                    $newEnd = $end;

                    if ($booked > 0) {
                        $notes[] = 'Customers emailed with options';
                    }
                }
            }
        }

        $newTrainer = $criteria->trainerId !== null && $criteria->trainerId !== $slot->trainer_id ? $criteria->trainerId : null;

        if ($newStart === null && $newCapacity === null && $newTrainer === null) {
            return $skip($notes[0] ?? 'Already like this');
        }

        return new BulkScheduleItem(
            BulkScheduleItem::UPDATE, $slot->id, $slot->starts_at, $slot->ends_at, $slot->capacity,
            newStartsAt: $newStart, newEndsAt: $newEnd, newCapacity: $newCapacity,
            trainerId: $slot->trainer_id, newTrainerId: $newTrainer,
            seatsBooked: $booked, seatsHeld: $held, note: $notes === [] ? null : implode('. ', $notes),
        );
    }

    private function clashNote(string $label): string
    {
        return sprintf('Within %d min of %s', $this->overlap->bufferMinutes(), $label);
    }
}
