<?php

declare(strict_types=1);

namespace App\Support\Weather;

use App\Enums\WeatherSeverity;

/**
 * Result of AssessSlotWeather: the level, each crossed condition at its
 * worst hour, and the forecast hours of the session (UTC ISO strings).
 */
final readonly class SlotAssessment
{
    /**
     * @param  list<array{key: string, label: string, value: float|int, unit: string, at: string}>  $crossed
     * @param  list<array{at: string, rain_probability: int|null, precipitation_mm: float|null, wind_gusts_kmh: float|null, temperature_c: float|null, weather_code: int|null}>  $hours
     */
    public function __construct(
        public WeatherSeverity $severity,
        public array $crossed,
        public array $hours,
    ) {}

    /**
     * @return array{crossed: list<array{key: string, label: string, value: float|int, unit: string, at: string}>, hours: list<array<string, mixed>>}
     */
    public function conditions(): array
    {
        return ['crossed' => $this->crossed, 'hours' => $this->hours];
    }
}
