<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ScheduleRule;
use Carbon\CarbonImmutable;

/**
 * Upcoming session times computed from schedule rules, in the display
 * timezone. Milestone 3 generates real slots; until then this drives the
 * /schedule shell and the homepage "This weekend" strip.
 */
final class SessionTimes
{
    public function __construct(private readonly BusinessFacts $facts) {}

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.display_timezone'));
    }

    /** Monday of the week containing $date (local). */
    public static function weekStart(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /**
     * Seven days from Monday, each with its sessions. Past sessions are left out.
     *
     * @return list<array{date: CarbonImmutable, sessions: list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, capacity: int}>}>
     */
    public function week(CarbonImmutable $monday): array
    {
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $monday->addDays($i);
            $days[] = ['date' => $date, 'sessions' => $this->on($date)];
        }

        return $days;
    }

    /**
     * Sessions on the coming weekend: today and tomorrow if it is already the
     * weekend, otherwise next Saturday and Sunday.
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, capacity: int}>
     */
    public function thisWeekend(): array
    {
        $today = self::now()->startOfDay();
        $saturday = $today->isSunday() ? $today->subDay() : ($today->isSaturday() ? $today : $today->next(CarbonImmutable::SATURDAY));

        return [...$this->on($saturday), ...$this->on($saturday->addDay())];
    }

    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, capacity: int}>
     */
    public function on(CarbonImmutable $date): array
    {
        $now = self::now();

        return array_values($this->facts->rules()
            ->filter(fn (ScheduleRule $rule): bool => $rule->appliesOn($date))
            ->sortBy(fn (ScheduleRule $rule): string => $rule->startTime())
            ->map(fn (ScheduleRule $rule): array => [
                'starts_at' => $rule->startsOn($date),
                'ends_at' => $rule->endsOn($date),
                'capacity' => $rule->capacity,
            ])
            ->filter(fn (array $session): bool => $session['starts_at']->greaterThan($now))
            ->all());
    }
}
