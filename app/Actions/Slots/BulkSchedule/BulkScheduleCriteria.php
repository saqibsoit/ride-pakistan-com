<?php

declare(strict_types=1);

namespace App\Actions\Slots\BulkSchedule;

use App\Enums\BulkScheduleOperation;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the admin asked the bulk schedule editor to do (prompt 15A), from
 * the form. Dates and times are Pakistan wall-clock values.
 *
 * - Create: one slot per matching date at $startTime–$endTime with
 *   $capacity, $trainerId and $type ('group' or 'private').
 * - Update: matching slots get any of $startTime/$endTime, $capacity,
 *   $trainerId (null = unchanged).
 * - Close / Cancel: matching slots ($reason required to cancel).
 *
 * Matching slots: start after now, on a Pakistan date in the range and a
 * picked weekday, optionally starting at $matchStart, of $type (null = any).
 */
final class BulkScheduleCriteria
{
    public const MAX_DAYS = 366;

    /**
     * @param  list<int>  $days  Carbon day numbers: 0 = Sunday … 6 = Saturday
     */
    public function __construct(
        public readonly BulkScheduleOperation $operation,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $until,
        public readonly array $days,
        public readonly ?string $matchStart = null,
        public readonly ?string $type = null,
        public readonly ?string $startTime = null,
        public readonly ?string $endTime = null,
        public readonly ?int $capacity = null,
        public readonly ?int $trainerId = null,
        public readonly ?string $reason = null,
    ) {}

    /**
     * Builds and checks the criteria from the form's state.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException with a message for the admin
     */
    public static function fromForm(array $data): self
    {
        $tz = Slot::displayTimezone();
        $operation = $data['operation'] instanceof BulkScheduleOperation
            ? $data['operation']
            : BulkScheduleOperation::tryFrom((string) ($data['operation'] ?? ''));

        if ($operation === null) {
            throw new InvalidArgumentException('Choose what to do.');
        }

        $from = self::date($data['from'] ?? null, $tz);
        $until = self::date($data['until'] ?? null, $tz);

        if ($from === null || $until === null) {
            throw new InvalidArgumentException('Choose a date range.');
        }

        if ($until->lessThan($from)) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }

        if ($from->diffInDays($until) >= self::MAX_DAYS) {
            throw new InvalidArgumentException('Choose at most '.self::MAX_DAYS.' days.');
        }

        $days = array_values(array_unique(array_map('intval', array_filter((array) ($data['days'] ?? []), 'is_numeric'))));
        $days = array_values(array_filter($days, fn (int $d): bool => $d >= 0 && $d <= 6));
        sort($days);

        if ($days === []) {
            throw new InvalidArgumentException('Pick at least one day of the week.');
        }

        $start = self::time($data['start_time'] ?? null);
        $end = self::time($data['end_time'] ?? null);

        if (($start === null) !== ($end === null)) {
            throw new InvalidArgumentException('Give both a start and an end time.');
        }

        if ($start !== null && $end !== null && $end <= $start) {
            throw new InvalidArgumentException('The end time must be after the start time.');
        }

        $capacity = is_numeric($data['capacity'] ?? null) ? (int) $data['capacity'] : null;
        $trainerId = is_numeric($data['trainer_id'] ?? null) ? (int) $data['trainer_id'] : null;
        $type = in_array($data['type'] ?? null, ['group', 'private'], true) ? (string) $data['type'] : null;
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($operation === BulkScheduleOperation::Create) {
            if ($start === null) {
                throw new InvalidArgumentException('Give the start and end time of the new slots.');
            }

            if ($capacity === null) {
                throw new InvalidArgumentException('Give the capacity of the new slots.');
            }

            $type ??= 'group';
        }

        if ($operation === BulkScheduleOperation::Update && $start === null && $capacity === null && $trainerId === null) {
            throw new InvalidArgumentException('Choose a new time, capacity or trainer.');
        }

        if ($operation === BulkScheduleOperation::Cancel && $reason === '') {
            throw new InvalidArgumentException('Give a reason for cancelling. Customers see it.');
        }

        return new self(
            operation: $operation,
            from: $from,
            until: $until,
            days: $days,
            matchStart: $operation === BulkScheduleOperation::Create ? null : self::time($data['match_start'] ?? null),
            type: $type,
            startTime: $operation === BulkScheduleOperation::Update || $operation === BulkScheduleOperation::Create ? $start : null,
            endTime: $operation === BulkScheduleOperation::Update || $operation === BulkScheduleOperation::Create ? $end : null,
            capacity: $operation === BulkScheduleOperation::Update || $operation === BulkScheduleOperation::Create ? $capacity : null,
            trainerId: $operation === BulkScheduleOperation::Update || $operation === BulkScheduleOperation::Create ? $trainerId : null,
            reason: $reason !== '' ? mb_substr($reason, 0, 500) : null,
        );
    }

    /**
     * The Pakistan dates in the range on the picked weekdays.
     *
     * @return list<CarbonImmutable> local midnight of each date
     */
    public function dates(): array
    {
        $dates = [];

        for ($date = $this->from; $date->lessThanOrEqualTo($this->until); $date = $date->addDay()) {
            if (in_array($date->dayOfWeek, $this->days, true)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /** A Pakistan date plus "HH:MM", in UTC. */
    public static function at(CarbonImmutable $localDate, string $time): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $localDate->setTimezone(Slot::displayTimezone())->startOfDay()->setTime($h, $m)->utc();
    }

    private static function date(mixed $value, string $tz): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        return CarbonImmutable::parse(substr($value, 0, 10), $tz)->startOfDay();
    }

    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $value)) {
            return null;
        }

        return substr($value, 0, 5);
    }
}
