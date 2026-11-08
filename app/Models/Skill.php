<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsContentActivity;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One item of the rider skill checklist (milestone 10d). Admins manage the
 * list; trainers tick skills for riders after a session. A skill riders
 * have reached is switched off, never deleted.
 *
 * @property int $id
 * @property string|null $key shipped skills only; seeded once
 * @property string $name
 * @property string|null $description
 * @property int $sort
 * @property bool $is_active
 */
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory, LogsContentActivity;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'sort',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort' => 0,
        'is_active' => true,
    ];

    /**
     * @return HasMany<RiderSkill, $this>
     */
    public function riderSkills(): HasMany
    {
        return $this->hasMany(RiderSkill::class);
    }

    /**
     * @param  Builder<Skill>  $query
     * @return Builder<Skill>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Skill>  $query
     * @return Builder<Skill>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
