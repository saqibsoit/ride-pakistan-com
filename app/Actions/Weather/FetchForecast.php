<?php

declare(strict_types=1);

namespace App\Actions\Weather;

use App\Enums\SettingKey;
use App\Exceptions\WeatherUnavailable;
use App\Models\WeatherFetch;
use App\Models\WeatherForecastHour;
use App\Support\Settings;
use App\Support\Weather\OpenMeteoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the venue's hourly forecast and caches it in
 * weather_forecast_hours (milestone 10f). Twice a day (FETCH_HOURS,
 * Pakistan time); after a failure the hourly run retries. Every attempt is
 * recorded in weather_fetches; a failure is logged and may alert the admins
 * (AlertForecastFailure). It never throws.
 */
final class FetchForecast
{
    /** Pakistan hours of the scheduled fetches. */
    public const FETCH_HOURS = [6, 18];

    /** Old forecast hours and fetch records are pruned after these. */
    private const KEEP_HOURS_DAYS = 2;

    private const KEEP_FETCHES_DAYS = 30;

    public function __construct(
        private readonly Settings $settings,
        private readonly OpenMeteoClient $client,
        private readonly AlertForecastFailure $alertFailure,
    ) {}

    /** Switched on and the coordinates are set. */
    public function enabled(): bool
    {
        return $this->coordinates() !== null;
    }

    /**
     * The cached forecast is older than the latest scheduled fetch time
     * (06:00 or 18:00 Pakistan time), or there is none: fetch now.
     */
    public function isDue(?CarbonImmutable $now = null): bool
    {
        $last = WeatherFetch::lastSuccess();

        return $last === null || $last->attempted_at->lessThan(self::latestScheduledTime($now ?? CarbonImmutable::now()));
    }

    public static function latestScheduledTime(CarbonImmutable $now): CarbonImmutable
    {
        $local = $now->setTimezone((string) config('app.display_timezone'));
        $candidates = [];

        foreach ([0, 1] as $daysBack) {
            foreach (self::FETCH_HOURS as $hour) {
                $at = $local->subDays($daysBack)->setTime($hour, 0);

                if ($at->lessThanOrEqualTo($local)) {
                    $candidates[] = $at;
                }
            }
        }

        usort($candidates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $b <=> $a);

        return $candidates[0]->utc();
    }

    /**
     * @return bool|null true fetched, false failed, null switched off
     */
    public function handle(): ?bool
    {
        $coordinates = $this->coordinates();

        if ($coordinates === null) {
            return null;
        }

        $now = CarbonImmutable::now();

        try {
            $hours = $this->client->hourly($coordinates[0], $coordinates[1]);
        } catch (WeatherUnavailable $e) {
            Log::warning('Weather forecast fetch failed.', ['error' => $e->getMessage()]);

            WeatherFetch::query()->create([
                'attempted_at' => $now,
                'succeeded' => false,
                'error' => mb_substr($e->getMessage(), 0, 255),
            ]);

            $this->alertFailure->handle($now);

            return false;
        }

        DB::transaction(function () use ($hours, $now): void {
            $rows = array_map(static fn (array $hour): array => [
                'hour_at' => $hour['hour_at']->toDateTimeString(),
                'precipitation_probability' => $hour['precipitation_probability'],
                'precipitation_mm' => $hour['precipitation_mm'],
                'wind_speed_kmh' => $hour['wind_speed_kmh'],
                'wind_gusts_kmh' => $hour['wind_gusts_kmh'],
                'temperature_c' => $hour['temperature_c'],
                'weather_code' => $hour['weather_code'],
                'fetched_at' => $now->toDateTimeString(),
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ], $hours);

            foreach (array_chunk($rows, 100) as $chunk) {
                WeatherForecastHour::query()->upsert(
                    $chunk,
                    ['hour_at'],
                    ['precipitation_probability', 'precipitation_mm', 'wind_speed_kmh', 'wind_gusts_kmh', 'temperature_c', 'weather_code', 'fetched_at', 'updated_at'],
                );
            }

            WeatherForecastHour::query()->where('hour_at', '<', $now->subDays(self::KEEP_HOURS_DAYS))->delete();

            WeatherFetch::query()->create(['attempted_at' => $now, 'succeeded' => true]);
            WeatherFetch::query()->where('attempted_at', '<', $now->subDays(self::KEEP_FETCHES_DAYS))->delete();
        });

        return true;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function coordinates(): ?array
    {
        if (! $this->settings->bool(SettingKey::WeatherEnabled)) {
            return null;
        }

        $lat = $this->settings->float(SettingKey::WeatherLatitude);
        $lng = $this->settings->float(SettingKey::WeatherLongitude);

        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return [$lat, $lng];
    }
}
