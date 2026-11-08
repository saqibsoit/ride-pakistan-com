<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UnavailabilityReason;
use Carbon\CarbonImmutable;
use Database\Factories\HorseUnavailabilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A date (Pakistan) a horse can't work: rest or vet day (milestone 10b).
 * AssignHorse refuses the horse for any session that day.
 *
 * @property int $id
 * @property int $horse_id
 * @property CarbonImmutable $date
 * @property UnavailabilityReason $reason
 * @property string|null $note
 * @property int|null $created_by
 * @property-read Horse $horse
 */
class HorseUnavailability extends Model
{
    /** @use HasFactory<HorseUnavailabilityFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'horse_id',
        'date',
        'reason',
        'note',
        'created_by',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('horses')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @return BelongsTo<Horse, $this>
     */
    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'reason' => UnavailabilityReason::class,
        ];
    }
}
