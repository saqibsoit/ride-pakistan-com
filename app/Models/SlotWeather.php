<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WeatherSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest forecast assessment of a slot (milestone 10f), written only by
 * CheckSlotWeather. alerted_severity is the level the team was last emailed
 * about: a new email goes out only when the level differs from it.
 *
 * conditions: {crossed: list<{key, label, value, unit, at}>, hours: list<{at, rain_probability, precipitation_mm, wind_gusts_kmh, temperature_c, weather_code}>}
 *
 * @property int $id
 * @property int $slot_id
 * @property WeatherSeverity $severity
 * @property array{crossed: list<array{key: string, label: string, value: float|int, unit: string, at: string}>, hours: list<array<string, mixed>>} $conditions
 * @property CarbonImmutable $assessed_at
 * @property WeatherSeverity|null $alerted_severity
 * @property CarbonImmutable|null $alerted_at
 * @property-read Slot $slot
 */
class SlotWeather extends Model
{
    protected $table = 'slot_weather';

    /** @var list<string> */
    protected $fillable = [
        'slot_id',
        'severity',
        'conditions',
        'assessed_at',
        'alerted_severity',
        'alerted_at',
    ];

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * Crossed conditions in a short line, e.g. "gusts 55 km/h, rain 80%".
     */
    public function summary(): string
    {
        return implode(', ', array_map(
            static fn (array $c): string => $c['key'] === 'thunderstorm'
                ? $c['label']
                : $c['label'].' '.self::number($c['value']).$c['unit'],
            $this->conditions['crossed'],
        ));
    }

    public static function number(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => WeatherSeverity::class,
            'conditions' => 'array',
            'assessed_at' => 'immutable_datetime',
            'alerted_severity' => WeatherSeverity::class,
            'alerted_at' => 'immutable_datetime',
        ];
    }
}
