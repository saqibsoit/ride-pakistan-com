<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to fetch the forecast (milestone 10f). Failures since the
 * last success drive the "forecasts failing" admin email.
 *
 * @property int $id
 * @property CarbonImmutable $attempted_at
 * @property bool $succeeded
 * @property string|null $error
 */
class WeatherFetch extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'attempted_at',
        'succeeded',
        'error',
    ];

    public static function lastSuccess(): ?self
    {
        return self::query()->where('succeeded', true)->latest('attempted_at')->latest('id')->first();
    }

    /**
     * @param  Builder<WeatherFetch>  $query
     */
    public function scopeFailed(Builder $query): void
    {
        $query->where('succeeded', false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempted_at' => 'immutable_datetime',
            'succeeded' => 'boolean',
        ];
    }
}
