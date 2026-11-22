<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsContentActivity;
use App\Observers\PublicContentObserver;
use Database\Factories\RideEventCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-editable event category (camp, workshop, trip, ride…), the
 * filter chips on /events.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int $sort
 * @property bool $is_active
 */
#[ObservedBy(PublicContentObserver::class)]
class RideEventCategory extends Model
{
    /** @use HasFactory<RideEventCategoryFactory> */
    use HasFactory, LogsContentActivity;

    /** @var list<string> */
    protected $fillable = ['slug', 'name', 'sort', 'is_active'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'sort' => 0];

    /**
     * @return HasMany<RideEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RideEvent::class);
    }

    /**
     * @param  Builder<RideEventCategory>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('name');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }
}
