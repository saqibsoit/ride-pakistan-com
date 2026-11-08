<?php

declare(strict_types=1);

use App\Actions\Weather\AssessSlotWeather;
use App\Enums\WeatherSeverity;
use App\Models\Slot;
use App\Models\WeatherForecastHour;
use App\Support\Weather\SlotAssessment;
use App\Support\Weather\WeatherThresholds;
use Carbon\CarbonImmutable;

/** Sat 14 Nov 2026 08:00–10:00 Pakistan time = 03:00–05:00 UTC. */
function assessSlot(): Slot
{
    $slot = new Slot;
    $slot->starts_at = CarbonImmutable::parse('2026-11-14 03:00', 'UTC');
    $slot->ends_at = CarbonImmutable::parse('2026-11-14 05:00', 'UTC');

    return $slot;
}

/**
 * Calm hours 01:00–07:00 UTC, with overrides keyed by the UTC hour ("03").
 *
 * @param  array<string, array<string, mixed>>  $overrides
 * @return list<WeatherForecastHour>
 */
function assessHours(array $overrides = []): array
{
    $hours = [];

    foreach (['01', '02', '03', '04', '05', '06'] as $h) {
        $hour = new WeatherForecastHour;
        $hour->forceFill(array_merge([
            'hour_at' => CarbonImmutable::parse('2026-11-14 '.$h.':00', 'UTC'),
            'precipitation_probability' => 5,
            'precipitation_mm' => 0.0,
            'wind_speed_kmh' => 8.0,
            'wind_gusts_kmh' => 15.0,
            'temperature_c' => 18.0,
            'weather_code' => 1,
        ], $overrides[$h] ?? []));
        $hours[] = $hour;
    }

    return $hours;
}

function assessThresholds(): WeatherThresholds
{
    return new WeatherThresholds(70, 2.0, 40, 38, 5, [95, 96, 99]);
}

function assess(array $overrides = []): ?SlotAssessment
{
    return (new AssessSlotWeather)->handle(assessSlot(), assessHours($overrides), assessThresholds());
}

it('is clear when nothing crosses a threshold, and covers only the session hours', function (): void {
    $result = assess();

    expect($result?->severity)->toBe(WeatherSeverity::Clear)
        ->and($result?->crossed)->toBe([])
        ->and(array_column($result?->hours ?? [], 'at'))->toBe(['2026-11-14T03:00:00Z', '2026-11-14T04:00:00Z']);
});

it('makes a single crossed threshold a watch', function (string $hour, array $values, string $key, int|float $peak): void {
    $result = assess([$hour => $values]);

    expect($result?->severity)->toBe(WeatherSeverity::Watch)
        ->and($result?->crossed)->toHaveCount(1)
        ->and($result?->crossed[0]['key'])->toBe($key)
        ->and($result?->crossed[0]['value'])->toBe($peak)
        ->and($result?->crossed[0]['at'])->toBe('2026-11-14T'.$hour.':00:00Z');
})->with([
    'rain chance' => ['03', ['precipitation_probability' => 80], 'rain_probability', 80],
    'rain amount' => ['04', ['precipitation_mm' => 3.5], 'precipitation', 3.5],
    'wind gusts' => ['04', ['wind_gusts_kmh' => 55.0], 'wind', 55],
    'heat' => ['04', ['temperature_c' => 39.5], 'heat', 39.5],
    'cold' => ['03', ['temperature_c' => 2.0], 'cold', 2],
]);

it('counts a value exactly at the threshold as crossed', function (): void {
    expect(assess(['03' => ['wind_gusts_kmh' => 40.0]])?->severity)->toBe(WeatherSeverity::Watch)
        ->and(assess(['03' => ['temperature_c' => 5.0]])?->severity)->toBe(WeatherSeverity::Watch)
        ->and(assess(['03' => ['wind_gusts_kmh' => 39.9]])?->severity)->toBe(WeatherSeverity::Clear);
});

it('makes a thunderstorm a warning on its own', function (): void {
    $result = assess(['04' => ['weather_code' => 95]]);

    expect($result?->severity)->toBe(WeatherSeverity::Warning)
        ->and($result?->crossed[0]['key'])->toBe('thunderstorm');
});

it('makes two crossed thresholds a warning, each at its worst hour', function (): void {
    $result = assess([
        '03' => ['precipitation_probability' => 75, 'wind_gusts_kmh' => 45.0],
        '04' => ['precipitation_probability' => 90],
    ]);

    expect($result?->severity)->toBe(WeatherSeverity::Warning)
        ->and(array_column($result?->crossed ?? [], 'key'))->toBe(['rain_probability', 'wind'])
        ->and($result?->crossed[0]['value'])->toBe(90)
        ->and($result?->crossed[0]['at'])->toBe('2026-11-14T04:00:00Z');
});

it('ignores bad weather outside the session hours', function (): void {
    expect(assess(['02' => ['weather_code' => 99], '05' => ['wind_gusts_kmh' => 80.0]])?->severity)->toBe(WeatherSeverity::Clear);
});

it('returns nothing without forecast hours for the session', function (): void {
    expect((new AssessSlotWeather)->handle(assessSlot(), [], assessThresholds()))->toBeNull();
});

it('skips a switched-off threshold', function (): void {
    $thresholds = new WeatherThresholds(null, 2.0, 40, 38, 5, []);

    expect((new AssessSlotWeather)->handle(assessSlot(), assessHours(['03' => ['precipitation_probability' => 100, 'weather_code' => 95]]), $thresholds)?->severity)
        ->toBe(WeatherSeverity::Clear);
});
