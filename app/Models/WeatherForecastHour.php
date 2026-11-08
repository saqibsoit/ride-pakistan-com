<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One forecast hour for the venue (UTC), from Open-Meteo (milestone 10f).
 * Written only by FetchForecast; the latest fetch replaces earlier values.
 *
 * @property int $id
 * @property CarbonImmutable $hour_at
 * @property int|null $precipitation_probability
 * @property float|null $precipitation_mm
 * @property float|null $wind_speed_kmh
 * @property float|null $wind_gusts_kmh
 * @property float|null $temperature_c
 * @property int|null $weather_code
 * @property CarbonImmutable $fetched_at
 */
class WeatherForecastHour extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'hour_at',
        'precipitation_probability',
        'precipitation_mm',
        'wind_speed_kmh',
        'wind_gusts_kmh',
        'temperature_c',
        'weather_code',
        'fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hour_at' => 'immutable_datetime',
            'precipitation_probability' => 'integer',
            'precipitation_mm' => 'float',
            'wind_speed_kmh' => 'float',
            'wind_gusts_kmh' => 'float',
            'temperature_c' => 'float',
            'weather_code' => 'integer',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
