<?php

declare(strict_types=1);

namespace App\Actions\Slots\BulkSchedule;

/**
 * What applying the bulk schedule criteria would do (prompt 15A), slot by
 * slot, in start order. Read-only.
 */
final class BulkSchedulePreview
{
    /**
     * @param  list<BulkScheduleItem>  $items
     */
    public function __construct(public readonly array $items) {}

    /**
     * @return list<BulkScheduleItem>
     */
    public function actionable(): array
    {
        return array_values(array_filter($this->items, fn (BulkScheduleItem $i): bool => $i->kind !== BulkScheduleItem::SKIP));
    }

    public function count(string $kind): int
    {
        return count(array_filter($this->items, fn (BulkScheduleItem $i): bool => $i->kind === $kind));
    }

    /** Riders booked in slots whose time changes or that get cancelled. */
    public function ridersAffected(): int
    {
        return array_sum(array_map(
            fn (BulkScheduleItem $i): int => ($i->kind === BulkScheduleItem::CANCEL || ($i->kind === BulkScheduleItem::UPDATE && $i->changesTime())) ? $i->seatsBooked : 0,
            $this->items,
        ));
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function toArray(): array
    {
        return array_map(fn (BulkScheduleItem $i): array => $i->toArray(), $this->items);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function fromArray(array $rows): self
    {
        return new self(array_map(fn (array $row): BulkScheduleItem => BulkScheduleItem::fromArray($row), $rows));
    }
}
