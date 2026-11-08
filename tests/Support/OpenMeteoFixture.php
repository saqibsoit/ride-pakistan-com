<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Settings\UpdateSettings;
use App\Models\WeatherForecastHour;
use Carbon\CarbonImmutable;

/**
 * Open-Meteo responses and cached forecast rows for the weather tests
 * (milestone 10f). Calm weather by default; pass overrides per UTC hour
 * ("2026-11-14 03:00" => ['wind_gusts_10m' => 55]).
 */
final class OpenMeteoFixture
{
    public const CALM = [
        'precipitation_probability' => 5,
        'precipitation' => 0.0,
        'wind_speed_10m' => 8.0,
        'wind_gusts_10m' => 15.0,
        'temperature_2m' => 18.0,
        'weather_code' => 1,
    ];

    /**
     * Hourly forecast JSON for 72 hours from the start of $fromUtc's day.
     *
     * @param  array<string, array<string, int|float|null>>  $overrides  keyed by UTC "Y-m-d H:i"
     * @return array<string, mixed>
     */
    public static function response(string $fromUtc, array $overrides = []): array
    {
        $start = CarbonImmutable::parse($fromUtc, 'UTC')->startOfDay();
        $hourly = ['time' => []];

        foreach (array_keys(self::CALM) as $name) {
            $hourly[$name] = [];
        }

        for ($i = 0; $i < 72; $i++) {
            $at = $start->addHours($i);
            $values = array_merge(self::CALM, $overrides[$at->format('Y-m-d H:i')] ?? []);
            $hourly['time'][] = $at->format('Y-m-d\TH:i');

            foreach ($values as $name => $value) {
                $hourly[$name][] = $value;
            }
        }

        return [
            'latitude' => 33.69,
            'longitude' => 73.05,
            'timezone' => 'UTC',
            'hourly_units' => ['time' => 'iso8601'],
            'hourly' => $hourly,
        ];
    }

    /** Switch alerts on with test coordinates (plus any other settings). */
    public static function enable(array $settings = []): void
    {
        app(UpdateSettings::class)->handle([
            'weather.enabled' => true,
            'weather.latitude' => 33.6844,
            'weather.longitude' => 73.0479,
            ...$settings,
        ]);
    }

    /**
     * Cache forecast rows directly (calm unless overridden), for the hours
     * [$fromUtc, $fromUtc + $hours).
     *
     * @param  array<string, array<string, int|float|null>>  $overrides  keyed by UTC "Y-m-d H:i", model column names
     */
    public static function cache(string $fromUtc, int $hours = 72, array $overrides = []): void
    {
        $start = CarbonImmutable::parse($fromUtc, 'UTC')->startOfHour();

        for ($i = 0; $i < $hours; $i++) {
            $at = $start->addHours($i);

            WeatherForecastHour::query()->updateOrCreate(['hour_at' => $at], array_merge([
                'precipitation_probability' => 5,
                'precipitation_mm' => 0.0,
                'wind_speed_kmh' => 8.0,
                'wind_gusts_kmh' => 15.0,
                'temperature_c' => 18.0,
                'weather_code' => 1,
                'fetched_at' => CarbonImmutable::now(),
            ], $overrides[$at->format('Y-m-d H:i')] ?? []));
        }
    }
}
