<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A skill a rider has shown (milestone 10d), ticked by the trainer from a
 * session's run sheet (RecordRiderProgress). One per rider and skill.
 *
 * @property int $id
 * @property int $rider_id
 * @property int $skill_id
 * @property int|null $booking_id the session it was ticked in
 * @property int|null $ticked_by
 * @property CarbonImmutable $achieved_at
 * @property-read Rider $rider
 * @property-read Skill $skill
 * @property-read Booking|null $booking
 */
class RiderSkill extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'rider_id',
        'skill_id',
        'booking_id',
        'ticked_by',
        'achieved_at',
    ];

    /**
     * @return BelongsTo<Rider, $this>
     */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'achieved_at' => 'immutable_datetime',
        ];
    }
}
