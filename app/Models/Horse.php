<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HorseLevel;
use App\Events\ImageUploaded;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\HorseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A horse (milestone 10b). Staff and trainers assign horses to riders on
 * the run sheet (AssignHorse). A horse that has carried riders is retired,
 * never deleted (RetireHorse / RestoreHorse).
 *
 * @property int $id
 * @property string $name
 * @property string|null $photo path on the site disk
 * @property string|null $temperament_notes
 * @property Collection<int, HorseLevel> $suitable_levels
 * @property int $max_sessions_per_day
 * @property bool $is_active
 * @property CarbonImmutable|null $retired_at
 * @property int $sort
 */
class Horse extends Model
{
    /** @use HasFactory<HorseFactory> */
    use HasFactory, LogsActivity;

    /**
     * Retirement is not fillable: it changes through RetireHorse and
     * RestoreHorse, which log the reason.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'photo',
        'temperament_notes',
        'suitable_levels',
        'max_sessions_per_day',
        'is_active',
        'sort',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'max_sessions_per_day' => 2,
        'sort' => 0,
    ];

    protected static function booted(): void
    {
        static::saved(function (Horse $horse): void {
            if (($horse->wasChanged('photo') || $horse->wasRecentlyCreated) && is_string($horse->photo) && str_starts_with($horse->photo, 'uploads/')) {
                ImageUploaded::dispatch('site', $horse->photo);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('horses')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /** Can be assigned at all (dates and daily limits are checked by AssignHorse). */
    public function isWorking(): bool
    {
        return $this->is_active && ! $this->isRetired();
    }

    public function suits(HorseLevel $level): bool
    {
        return $this->suitable_levels->contains($level);
    }

    /** Off work on this Pakistan date (rest or vet day). */
    public function unavailableOn(CarbonInterface $localDate): ?HorseUnavailability
    {
        return $this->unavailabilities()->whereDate('date', $localDate->format('Y-m-d'))->first();
    }

    /**
     * @param  Builder<Horse>  $query
     * @return Builder<Horse>
     */
    public function scopeWorking(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('retired_at');
    }

    /**
     * @return HasMany<HorseUnavailability, $this>
     */
    public function unavailabilities(): HasMany
    {
        return $this->hasMany(HorseUnavailability::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suitable_levels' => AsEnumCollection::of(HorseLevel::class),
            'max_sessions_per_day' => 'integer',
            'is_active' => 'boolean',
            'retired_at' => 'immutable_datetime',
            'sort' => 'integer',
        ];
    }
}
