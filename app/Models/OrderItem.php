<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $slot_id
 * @property int|null $rider_id
 * @property int $unit_price_minor
 * @property int|null $ride_event_tier_id event orders: the attendee's price tier
 * @property array<string, string>|null $answers event orders: this attendee's answers, keyed by question (encrypted)
 * @property-read RideEventTier|null $tier
 * @property-read Order $order
 * @property-read Slot|null $slot
 * @property-read Rider|null $rider
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'slot_id',
        'rider_id',
        'unit_price_minor',
        'ride_event_tier_id',
        'answers',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * @return BelongsTo<Rider, $this>
     */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    /**
     * @return BelongsTo<RideEventTier, $this>
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(RideEventTier::class, 'ride_event_tier_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'answers' => 'encrypted:array',
        ];
    }
}
