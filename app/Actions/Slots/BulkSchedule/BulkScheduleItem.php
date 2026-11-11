<?php

declare(strict_types=1);

namespace App\Actions\Slots\BulkSchedule;

use App\Actions\Slots\GenerateSlots;
use App\Models\Slot;
use Carbon\CarbonImmutable;

/**
 * One row of the bulk schedule preview (prompt 15A): a slot to create,
 * change, close or cancel, or one that will be skipped (with the reason).
 * Kept as plain values so the admin page can hold it between requests.
 */
final class BulkScheduleItem
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const CLOSE = 'close';

    public const CANCEL = 'cancel';

    public const SKIP = 'skip';

    public function __construct(
        public readonly string $kind,
        public readonly ?int $slotId,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly int $capacity,
        public readonly ?CarbonImmutable $newStartsAt = null,
        public readonly ?CarbonImmutable $newEndsAt = null,
        public readonly ?int $newCapacity = null,
        public readonly ?int $trainerId = null,
        public readonly ?int $newTrainerId = null,
        public readonly int $seatsBooked = 0,
        public readonly int $seatsHeld = 0,
        public readonly ?string $note = null,
    ) {}

    /** Stable id the admin confirms: the slot, or the new slot's start. */
    public function key(): string
    {
        return $this->slotId !== null ? 'slot:'.$this->slotId : 'new:'.GenerateSlots::key($this->startsAt);
    }

    public function changesTime(): bool
    {
        return $this->newStartsAt !== null
            && (! $this->newStartsAt->equalTo($this->startsAt) || ! ($this->newEndsAt?->equalTo($this->endsAt) ?? true));
    }

    public function label(): string
    {
        return self::range($this->startsAt, $this->endsAt);
    }

    public function newLabel(): ?string
    {
        return $this->changesTime() && $this->newStartsAt !== null && $this->newEndsAt !== null
            ? self::range($this->newStartsAt, $this->newEndsAt)
            : null;
    }

    /** "Sat 14 Nov 2026, 08:00–10:00" in Pakistan time. */
    public static function range(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $tz = Slot::displayTimezone();

        return $start->setTimezone($tz)->format('D j M Y, H:i').'–'.$end->setTimezone($tz)->format('H:i');
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'slot_id' => $this->slotId,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'capacity' => $this->capacity,
            'new_starts_at' => $this->newStartsAt?->toIso8601String(),
            'new_ends_at' => $this->newEndsAt?->toIso8601String(),
            'new_capacity' => $this->newCapacity,
            'trainer_id' => $this->trainerId,
            'new_trainer_id' => $this->newTrainerId,
            'seats_booked' => $this->seatsBooked,
            'seats_held' => $this->seatsHeld,
            'note' => $this->note,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $date = fn (mixed $v): ?CarbonImmutable => is_string($v) && $v !== '' ? CarbonImmutable::parse($v)->utc() : null;
        $int = fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;

        return new self(
            kind: (string) $row['kind'],
            slotId: $int($row['slot_id'] ?? null),
            startsAt: $date($row['starts_at'] ?? null) ?? CarbonImmutable::now(),
            endsAt: $date($row['ends_at'] ?? null) ?? CarbonImmutable::now(),
            capacity: (int) ($row['capacity'] ?? 0),
            newStartsAt: $date($row['new_starts_at'] ?? null),
            newEndsAt: $date($row['new_ends_at'] ?? null),
            newCapacity: $int($row['new_capacity'] ?? null),
            trainerId: $int($row['trainer_id'] ?? null),
            newTrainerId: $int($row['new_trainer_id'] ?? null),
            seatsBooked: (int) ($row['seats_booked'] ?? 0),
            seatsHeld: (int) ($row['seats_held'] ?? 0),
            note: isset($row['note']) && is_string($row['note']) ? $row['note'] : null,
        );
    }
}
