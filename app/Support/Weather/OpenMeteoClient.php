<?php

declare(strict_types=1);

namespace App\Support\Weather;

use App\Exceptions\WeatherUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Open-Meteo hourly forecast (free, no key; milestone 10f). Server-side
 * only. Times are requested in UTC. Any HTTP, connection or shape problem
 * becomes a WeatherUnavailable.
 */
final class OpenMeteoClient
{
    public const HOURLY = ['precipitation_probability', 'precipitation', 'wind_speed_10m', 'wind_gusts_10m', 'temperature_2m', 'weather_code'];

    public const FORECAST_DAYS = 3;

    /**
     * @return list<array{hour_at: CarbonImmutable, precipitation_probability: int|null, precipitation_mm: float|null, wind_speed_kmh: float|null, wind_gusts_kmh: float|null, temperature_c: float|null, weather_code: int|null}>
     *
     * @throws WeatherUnavailable
     */
    public function hourly(float $latitude, float $longitude): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.open_meteo.timeout', 10))
                ->retry(2, 500, throw: false)
                ->get((string) config('services.open_meteo.url'), [
                    'latitude' => round($latitude, 4),
                    'longitude' => round($longitude, 4),
                    'hourly' => implode(',', self::HOURLY),
                    'timezone' => 'UTC',
                    'timeformat' => 'iso8601',
                    'forecast_days' => self::FORECAST_DAYS,
                    'wind_speed_unit' => 'kmh',
                    'temperature_unit' => 'celsius',
                    'precipitation_unit' => 'mm',
                ]);
        } catch (ConnectionException|RequestException $e) {
            throw WeatherUnavailable::connection($e->getMessage());
        }

        if (! $response->successful()) {
            throw WeatherUnavailable::http($response->status());
        }

        $hourly = $response->json('hourly');

        if (! is_array($hourly) || ! isset($hourly['time']) || ! is_array($hourly['time']) || $hourly['time'] === []) {
            throw WeatherUnavailable::malformed('no hourly times');
        }

        $times = array_values($hourly['time']);
        $columns = [];

        foreach (self::HOURLY as $name) {
            if (! isset($hourly[$name]) || ! is_array($hourly[$name]) || count($hourly[$name]) !== count($times)) {
                throw WeatherUnavailable::malformed('missing '.$name);
            }

            $columns[$name] = array_values($hourly[$name]);
        }

        $hours = [];

        foreach ($times as $i => $time) {
            if (! is_string($time)) {
                throw WeatherUnavailable::malformed('bad time');
            }

            try {
                $at = CarbonImmutable::parse($time, 'UTC')->utc()->startOfHour();
            } catch (\Throwable) {
                throw WeatherUnavailable::malformed('bad time');
            }

            $hours[] = [
                'hour_at' => $at,
                'precipitation_probability' => self::int($columns['precipitation_probability'][$i]),
                'precipitation_mm' => self::float($columns['precipitation'][$i]),
                'wind_speed_kmh' => self::float($columns['wind_speed_10m'][$i]),
                'wind_gusts_kmh' => self::float($columns['wind_gusts_10m'][$i]),
                'temperature_c' => self::float($columns['temperature_2m'][$i]),
                'weather_code' => self::int($columns['weather_code'][$i]),
            ];
        }

        return $hours;
    }

    private static function float(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || is_float($value) ? (int) round((float) $value) : null;
    }
}
