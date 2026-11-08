<?php

declare(strict_types=1);

namespace App\Actions\Weather;

use App\Enums\WeatherSeverity;
use App\Models\Slot;
use App\Models\WeatherForecastHour;
use App\Support\Weather\SlotAssessment;
use App\Support\Weather\WeatherThresholds;

/**
 * Compares the forecast hours that overlap a session with the thresholds
 * (milestone 10f). Pure: no queries, no writes.
 *
 * - Each condition counts once, at its worst hour of the session.
 * - A value at the threshold counts as crossed.
 * - Warning: a thunderstorm code, or two or more conditions. Watch: one.
 * - No forecast hour overlaps the session: null (unknown, no badge).
 */
final class AssessSlotWeather
{
    /**
     * @param  iterable<WeatherForecastHour>  $forecast
     */
    public function handle(Slot $slot, iterable $forecast, WeatherThresholds $thresholds): ?SlotAssessment
    {
        $hours = [];

        foreach ($forecast as $hour) {
            // Hour [h, h+1) overlaps the session [start, end).
            if ($hour->hour_at->lessThan($slot->ends_at) && $hour->hour_at->addHour()->greaterThan($slot->starts_at)) {
                $hours[] = $hour;
            }
        }

        if ($hours === []) {
            return null;
        }

        usort($hours, static fn (WeatherForecastHour $a, WeatherForecastHour $b): int => $a->hour_at <=> $b->hour_at);

        $crossed = array_values(array_filter([
            self::peak($hours, 'rain_probability', 'rain chance', '%', $thresholds->rainProbability,
                static fn (WeatherForecastHour $h): ?float => $h->precipitation_probability === null ? null : (float) $h->precipitation_probability),
            self::peak($hours, 'precipitation', 'rain', ' mm', $thresholds->precipitationMm,
                static fn (WeatherForecastHour $h): ?float => $h->precipitation_mm),
            self::peak($hours, 'wind', 'gusts', ' km/h', $thresholds->windKmh,
                static fn (WeatherForecastHour $h): ?float => $h->wind_gusts_kmh ?? $h->wind_speed_kmh),
            self::peak($hours, 'heat', 'heat', '°C', $thresholds->heatC,
                static fn (WeatherForecastHour $h): ?float => $h->temperature_c),
            self::peak($hours, 'cold', 'cold', '°C', $thresholds->coldC === null ? null : -$thresholds->coldC,
                static fn (WeatherForecastHour $h): ?float => $h->temperature_c === null ? null : -$h->temperature_c, negate: true),
            self::thunder($hours, $thresholds->thunderCodes),
        ]));

        $thunder = in_array('thunderstorm', array_column($crossed, 'key'), true);

        $severity = match (true) {
            $thunder || count($crossed) >= 2 => WeatherSeverity::Warning,
            count($crossed) === 1 => WeatherSeverity::Watch,
            default => WeatherSeverity::Clear,
        };

        return new SlotAssessment($severity, $crossed, array_map(static fn (WeatherForecastHour $h): array => [
            'at' => $h->hour_at->utc()->toIso8601ZuluString(),
            'rain_probability' => $h->precipitation_probability,
            'precipitation_mm' => $h->precipitation_mm,
            'wind_gusts_kmh' => $h->wind_gusts_kmh ?? $h->wind_speed_kmh,
            'temperature_c' => $h->temperature_c,
            'weather_code' => $h->weather_code,
        ], $hours));
    }

    /**
     * The worst hour of one condition, if it reaches the threshold.
     *
     * @param  list<WeatherForecastHour>  $hours
     * @param  callable(WeatherForecastHour): ?float  $value  bigger is worse
     * @return array{key: string, label: string, value: float|int, unit: string, at: string}|null
     */
    private static function peak(array $hours, string $key, string $label, string $unit, int|float|null $threshold, callable $value, bool $negate = false): ?array
    {
        if ($threshold === null) {
            return null;
        }

        $worst = null;
        $worstAt = null;

        foreach ($hours as $hour) {
            $v = $value($hour);

            if ($v !== null && ($worst === null || $v > $worst)) {
                $worst = $v;
                $worstAt = $hour->hour_at;
            }
        }

        if ($worst === null || $worstAt === null || $worst < (float) $threshold) {
            return null;
        }

        $shown = $negate ? -$worst : $worst;

        return [
            'key' => $key,
            'label' => $label,
            'value' => floor($shown) === $shown ? (int) $shown : round($shown, 1),
            'unit' => $unit,
            'at' => $worstAt->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  list<WeatherForecastHour>  $hours
     * @param  list<int>  $codes
     * @return array{key: string, label: string, value: int, unit: string, at: string}|null
     */
    private static function thunder(array $hours, array $codes): ?array
    {
        foreach ($hours as $hour) {
            if ($hour->weather_code !== null && in_array($hour->weather_code, $codes, true)) {
                return [
                    'key' => 'thunderstorm',
                    'label' => 'thunderstorm',
                    'value' => $hour->weather_code,
                    'unit' => '',
                    'at' => $hour->hour_at->utc()->toIso8601ZuluString(),
                ];
            }
        }

        return null;
    }
}
