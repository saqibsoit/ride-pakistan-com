<?php

declare(strict_types=1);

namespace App\Actions\Slots\BulkSchedule;

use App\Actions\Booking\SlotLock;
use App\Actions\Slots\ChangeSlotStatus;
use App\Actions\Slots\CreateManualSlot;
use App\Actions\Slots\ScheduleLock;
use App\Actions\Slots\SlotOverlap;
use App\Actions\Waitlist\OfferWaitlistSeats;
use App\Enums\BookingStatus;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\SlotNotCreated;
use App\Models\Booking;
use App\Models\Slot;
use App\Models\SlotChangeNotice;
use App\Models\User;
use App\Notifications\ScheduleChangedSummary;
use App\Notifications\SlotTimeChanged;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Saves what the admin confirmed in the bulk schedule preview (prompt 15A).
 *
 * The preview is worked out again and only the confirmed rows are applied,
 * each in its own transaction under its slot's row lock, with every check
 * repeated. A row that no longer matches the preview (a hold arrived, the
 * seats grew past the new capacity, another session now clashes) fails
 * and is reported; the other rows still go ahead.
 *
 * - A changed rule slot becomes a manual slot, so the generator stops
 *   resetting it; a moved one remembers the rule start it replaced.
 * - Moved slots: each booking's calendar sequence goes up, and every
 *   customer with confirmed bookings in it gets one SlotTimeChanged email
 *   with keep / move / cancel links (SlotChangeNotice).
 * - Close and cancel go through ChangeSlotStatus; cancelling runs the
 *   usual slot cancellation (credits back, paid bookings flagged, emails).
 * - Every trainer of a changed session gets one summary email.
 *
 * Audit log `slots`: one entry per change plus `bulk_applied` with the
 * batch id and counts. The page cache clears through SeatAvailabilityObserver.
 */
final class ApplyBulkSchedule
{
    public function __construct(
        private readonly PreviewBulkSchedule $preview,
        private readonly SlotLock $lock,
        private readonly SlotOverlap $overlap,
        private readonly CreateManualSlot $createSlot,
        private readonly ChangeSlotStatus $status,
        private readonly OfferWaitlistSeats $waitlist,
        private readonly Notifier $notifier,
        private readonly ScheduleLock $scheduleLock,
    ) {}

    /**
     * @param  list<BulkScheduleItem>  $confirmed  the preview rows the admin saw and confirmed
     *
     * @throws InvalidArgumentException
     */
    public function handle(BulkScheduleCriteria $criteria, array $confirmed, User $actor): BulkScheduleResult
    {
        if (! $actor->isAdmin()) {
            throw new InvalidArgumentException('Only admins can change the schedule in bulk.');
        }

        $result = new BulkScheduleResult((string) Str::uuid());
        $fresh = [];

        foreach ($this->preview->handle($criteria)->items as $item) {
            $fresh[$item->key()] = $item;
        }

        /** @var array<int, list<string>> $trainerLines trainer id => lines */
        $trainerLines = [];
        /** @var list<array{0: CarbonImmutable, 1: CarbonImmutable}> $planned */
        $planned = [];

        foreach ($confirmed as $wanted) {
            if ($wanted->kind === BulkScheduleItem::SKIP) {
                continue;
            }

            $now = $fresh[$wanted->key()] ?? null;

            if ($now === null || $now->kind !== $wanted->kind || ! self::sameChange($wanted, $now)) {
                $result->failures[] = $wanted->label().': changed since the preview'.($now?->note !== null ? ' ('.$now->note.')' : '').'. Preview again.';

                continue;
            }

            try {
                $line = match ($wanted->kind) {
                    BulkScheduleItem::CREATE => $this->create($wanted, $criteria, $actor, $result),
                    BulkScheduleItem::UPDATE => $this->update($wanted, $criteria, $actor, $result, $planned),
                    BulkScheduleItem::CLOSE => $this->close($wanted, $actor, $result),
                    BulkScheduleItem::CANCEL => $this->cancel($wanted, $criteria, $actor, $result),
                    default => null,
                };
            } catch (DomainException|InvalidArgumentException $e) {
                $result->failures[] = $wanted->label().': '.$e->getMessage();

                continue;
            }

            if ($line === null) {
                continue;
            }

            foreach (array_unique(array_filter([$wanted->trainerId, $wanted->newTrainerId])) as $trainerId) {
                $trainerLines[(int) $trainerId][] = $line;
            }
        }

        $this->notifyTrainers($trainerLines, $result);

        activity('slots')
            ->causedBy($actor)
            ->event('bulk_applied')
            ->withProperties([...$result->toArray(), 'operation' => $criteria->operation->value])
            ->log(sprintf('Bulk schedule: %d created, %d changed, %d closed, %d cancelled, %d failed',
                $result->created, $result->updated, $result->closed, $result->cancelled, count($result->failures)));

        return $result;
    }

    private function create(BulkScheduleItem $item, BulkScheduleCriteria $criteria, User $actor, BulkScheduleResult $result): string
    {
        $trainer = $item->newTrainerId !== null ? User::query()->find($item->newTrainerId) : null;

        // The schedule lock keeps private request approvals from taking the same time (prompt 15B).
        $slot = $this->scheduleLock->run(fn (): Slot => DB::transaction(function () use ($item, $criteria, $actor, $trainer): Slot {
            $clash = $this->overlap->firstClash($item->startsAt, $item->endsAt, lock: true);

            if ($clash !== null) {
                throw new SlotNotCreated(sprintf('within %d min of %s', $this->overlap->bufferMinutes(), $clash->label()));
            }

            return $this->createSlot->handle($item->startsAt, $item->endsAt, $item->capacity, $actor, $criteria->type === 'private', $trainer);
        }));

        activity('slots')->performedOn($slot)->causedBy($actor)->event('bulk_created')
            ->withProperties(['batch' => $result->batch])
            ->log('Bulk schedule: slot added '.$slot->label());

        $result->created++;

        return 'New session: '.$slot->label().', '.$slot->capacity.' riders';
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $planned
     */
    private function update(BulkScheduleItem $wanted, BulkScheduleCriteria $criteria, User $actor, BulkScheduleResult $result, array &$planned): string
    {
        /** @var list<SlotChangeNotice> $notices */
        $notices = [];
        $capacityUp = false;

        // A plain closure, not fn(): the inner transaction writes $notices and $capacityUp by reference.
        $line = $this->scheduleLock->run(function () use ($wanted, $criteria, $actor, $result, $planned, &$notices, &$capacityUp): string {
            return DB::transaction(function () use ($wanted, $criteria, $actor, $result, $planned, &$notices, &$capacityUp): string {
                $locked = $this->lock->acquire((int) $wanted->slotId);
                $slot = $locked->slot;

                if ($slot->status !== SlotStatus::Open || ! $slot->starts_at->isAfter(CarbonImmutable::now())) {
                    throw new DomainException('no longer open or already started');
                }

                $check = $this->preview->planUpdate($criteria, $slot, $locked->seatsBooked, $locked->seatsHeld, $planned, lock: true);

                if ($check->kind !== BulkScheduleItem::UPDATE || ! self::sameChange($wanted, $check)) {
                    throw new DomainException('changed since the preview ('.($check->note ?? 'seats or times moved').'). Preview again.');
                }

                $before = [
                    'starts_at' => $slot->starts_at->toIso8601String(),
                    'ends_at' => $slot->ends_at->toIso8601String(),
                    'capacity' => $slot->capacity,
                    'trainer_id' => $slot->trainer_id,
                    'source' => $slot->source->value,
                ];
                $oldStart = $slot->starts_at;
                $oldEnd = $slot->ends_at;
                $oldLabel = $slot->label();
                $moved = $check->changesTime() && $check->newStartsAt !== null && $check->newEndsAt !== null;

                if ($slot->source === SlotSource::Rule) {
                    // Detach from the rule so GenerateSlots stops resetting it.
                    if ($moved) {
                        $slot->replaces_starts_at ??= $slot->starts_at;
                    }

                    $slot->source = SlotSource::Manual;
                    $slot->schedule_rule_id = null;
                }

                if ($moved) {
                    $slot->starts_at = $check->newStartsAt;
                    $slot->ends_at = $check->newEndsAt;
                }

                if ($check->newCapacity !== null) {
                    $capacityUp = $check->newCapacity > $slot->capacity;
                    $slot->capacity = $check->newCapacity;
                }

                if ($check->newTrainerId !== null) {
                    $slot->trainer_id = $check->newTrainerId;
                }

                $slot->save();

                if ($moved) {
                    $bookings = Booking::query()
                        ->where('slot_id', $slot->id)
                        ->where('status', BookingStatus::Confirmed)
                        ->lockForUpdate()
                        ->get();

                    foreach ($bookings as $booking) {
                        $booking->calendar_sequence++; // the updated .ics replaces the old entry
                        $booking->save();
                    }

                    foreach ($bookings->pluck('user_id')->unique() as $userId) {
                        $notices[] = SlotChangeNotice::query()->create([
                            'batch' => $result->batch,
                            'slot_id' => $slot->id,
                            'user_id' => (int) $userId,
                            'old_starts_at' => $oldStart,
                            'old_ends_at' => $oldEnd,
                            'new_starts_at' => $slot->starts_at,
                            'new_ends_at' => $slot->ends_at,
                        ]);
                    }
                }

                activity('slots')->performedOn($slot)->causedBy($actor)->event('bulk_updated')
                    ->withProperties(['batch' => $result->batch, 'old' => $before, 'attributes' => [
                        'starts_at' => $slot->starts_at->toIso8601String(),
                        'ends_at' => $slot->ends_at->toIso8601String(),
                        'capacity' => $slot->capacity,
                        'trainer_id' => $slot->trainer_id,
                        'source' => $slot->source->value,
                    ], 'customers_notified' => count($notices)])
                    ->log('Bulk schedule: slot changed '.$oldLabel.($moved ? ' → '.$slot->label() : ''));

                $changes = [];

                if ($moved) {
                    $changes[] = 'moved to '.$slot->label();
                }

                if ($check->newCapacity !== null) {
                    $changes[] = 'capacity '.$slot->capacity;
                }

                if ($check->newTrainerId !== null) {
                    $changes[] = 'trainer changed';
                }

                return sprintf('%s: %s (%d booked)', $oldLabel, implode(', ', $changes), $locked->seatsBooked);
            });
        });

        if ($wanted->changesTime() && $wanted->newStartsAt !== null && $wanted->newEndsAt !== null) {
            $planned[] = [$wanted->newStartsAt, $wanted->newEndsAt];
        }

        foreach ($notices as $notice) {
            $notice->setRelation('user', User::query()->findOrFail($notice->user_id));

            if ($this->notifier->send($notice->user, new SlotTimeChanged($notice), 'slot_time_changed:'.$notice->id)) {
                $result->customersNotified++;
            }
        }

        if ($capacityUp) {
            $this->waitlist->handle((int) $wanted->slotId, $actor);
        }

        $result->updated++;

        return $line;
    }

    private function close(BulkScheduleItem $item, User $actor, BulkScheduleResult $result): string
    {
        $slot = Slot::query()->findOrFail($item->slotId);

        try {
            $this->status->handle($slot, SlotStatus::Closed, $actor);
        } catch (InvalidStatusTransition) {
            throw new DomainException('no longer open');
        }

        $result->closed++;

        return $slot->label().': closed ('.$item->seatsBooked.' booked, bookings stay)';
    }

    private function cancel(BulkScheduleItem $item, BulkScheduleCriteria $criteria, User $actor, BulkScheduleResult $result): string
    {
        $slot = Slot::query()->findOrFail($item->slotId);

        $customers = Booking::query()
            ->where('slot_id', $slot->id)
            ->where('status', BookingStatus::Confirmed)
            ->distinct()
            ->count('user_id');

        try {
            // SlotCancelled runs the usual cancellation: credits back, paid bookings flagged, one email each.
            $this->status->handle($slot, SlotStatus::Cancelled, $actor, $criteria->reason);
        } catch (InvalidStatusTransition $e) {
            throw new DomainException($e->getMessage());
        }

        $result->cancelled++;
        $result->customersNotified += $customers;

        return $slot->label().': cancelled ('.$item->seatsBooked.' booked)';
    }

    /**
     * @param  array<int, list<string>>  $lines
     */
    private function notifyTrainers(array $lines, BulkScheduleResult $result): void
    {
        foreach ($lines as $trainerId => $sessionLines) {
            $trainer = User::query()->find($trainerId);

            if ($trainer === null || ! $trainer->isTrainer()) {
                continue;
            }

            if ($this->notifier->send($trainer, new ScheduleChangedSummary($result->batch, $sessionLines), 'schedule_changed:'.$result->batch.':'.$trainer->id)) {
                $result->trainersNotified++;
            }
        }
    }

    /** The same change as the confirmed preview row. */
    private static function sameChange(BulkScheduleItem $a, BulkScheduleItem $b): bool
    {
        $same = fn (?CarbonImmutable $x, ?CarbonImmutable $y): bool => ($x === null && $y === null) || ($x !== null && $y !== null && $x->equalTo($y));

        return $a->changesTime() === $b->changesTime()
            && (! $a->changesTime() || ($same($a->newStartsAt, $b->newStartsAt) && $same($a->newEndsAt, $b->newEndsAt)))
            && $a->newCapacity === $b->newCapacity
            && $a->newTrainerId === $b->newTrainerId
            && $a->startsAt->equalTo($b->startsAt);
    }
}
