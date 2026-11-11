<?php

declare(strict_types=1);

namespace App\Actions\Slots\BulkSchedule;

/** What ApplyBulkSchedule did (prompt 15A), for the summary screen. */
final class BulkScheduleResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $closed = 0;

    public int $cancelled = 0;

    public int $customersNotified = 0;

    public int $trainersNotified = 0;

    /** @var list<string> "Sat 14 Nov 2026, 08:00–10:00: reason" */
    public array $failures = [];

    public function __construct(public readonly string $batch) {}

    public function changed(): int
    {
        return $this->created + $this->updated + $this->closed + $this->cancelled;
    }

    /**
     * @return array{batch: string, created: int, updated: int, closed: int, cancelled: int, customers_notified: int, trainers_notified: int, failures: list<string>}
     */
    public function toArray(): array
    {
        return [
            'batch' => $this->batch,
            'created' => $this->created,
            'updated' => $this->updated,
            'closed' => $this->closed,
            'cancelled' => $this->cancelled,
            'customers_notified' => $this->customersNotified,
            'trainers_notified' => $this->trainersNotified,
            'failures' => $this->failures,
        ];
    }
}
