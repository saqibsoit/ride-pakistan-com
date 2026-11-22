<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsContentActivity;
use App\Observers\PublicContentObserver;
use Database\Factories\RideEventTierFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price tier of an event (adult, child…; "Per person" when there is one).
 * seats_per_occurrence caps how many of this tier one occurrence takes, on
 * top of the occurrence's capacity; null = no cap of its own. Checked
 * under the slot row lock (SlotLock tier counts).
 *
 * @property int $id
 * @property int $ride_event_id
 * @property string $name
 * @property int $price_minor
 * @property int|null $seats_per_occurrence
 * @property int $sort
 * @property-read RideEvent $event
 */
#[ObservedBy(PublicContentObserver::class)]
class RideEventTier extends Model
{
    /** @use HasFactory<RideEventTierFactory> */
    use HasFactory, LogsContentActivity;

    /** @var list<string> */
    protected $fillable = ['name', 'price_minor', 'seats_per_occurrence', 'sort'];

    /**
     * @return BelongsTo<RideEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(RideEvent::class, 'ride_event_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'seats_per_occurrence' => 'integer',
            'sort' => 'integer',
        ];
    }
}
