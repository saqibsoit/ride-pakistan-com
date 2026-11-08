<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsContentActivity;
use App\Observers\PublicContentObserver;
use App\Observers\ScheduleRuleObserver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ScheduleRuleFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A weekly recurring session. Times are wall-clock in the display timezone
 * (Asia/Karachi); day_of_week follows Carbon (0 = Sunday ... 6 = Saturday).
 * Slots are generated from these rules by App\Actions\Slots\GenerateSlots.
 *
 * @property int $id
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property int $capacity
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property bool $is_active
 * @property int|null $trainer_id default trainer for the slots it generates (milestone 10b)
 * @property-read User|null $trainer
 */
#[ObservedBy([PublicContentObserver::class, ScheduleRuleObserver::class])]
class ScheduleRule extends Model
{
    /** @use HasFactory<ScheduleRuleFactory> */
    use HasFactory, LogsContentActivity;

    /** @var list<string> */
    protected $fillable = [
        'day_of_week',
        'start_time',
        'end_time',
        'capacity',
        'valid_from',
        'valid_to',
        'is_active',
        'trainer_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    /**
     * @return HasMany<Slot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class);
    }

    /**
     * @param  Builder<ScheduleRule>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Active rules that apply on a given local date.
     *
     * @param  Builder<ScheduleRule>  $query
     */
    public function scopeApplyingOn(Builder $query, CarbonInterface $date): void
    {
        $day = $date->toDateString();

        $query->where('is_active', true)
            ->where('day_of_week', $date->dayOfWeek)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day));
    }

    /**
     * @param  Builder<ScheduleRule>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('day_of_week')->orderBy('start_time');
    }

    /** "08:00" */
    public function startTime(): string
    {
        return substr($this->start_time, 0, 5);
    }

    /** "10:00" */
    public function endTime(): string
    {
        return substr($this->end_time, 0, 5);
    }

    /** Start of this rule's session on a local date, in the display timezone. */
    public function startsOn(CarbonInterface $date): CarbonImmutable
    {
        return $this->at($date, $this->startTime());
    }

    public function endsOn(CarbonInterface $date): CarbonImmutable
    {
        return $this->at($date, $this->endTime());
    }

    public function appliesOn(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $this->is_active
            && $this->day_of_week === $date->dayOfWeek
            && ($this->valid_from === null || $this->valid_from->toDateString() <= $day)
            && ($this->valid_to === null || $this->valid_to->toDateString() >= $day);
    }

    private function at(CarbonInterface $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $date->toDateString().' '.$time,
            (string) config('app.display_timezone'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'capacity' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }
}
