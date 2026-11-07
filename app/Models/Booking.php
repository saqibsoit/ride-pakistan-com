<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rider's seat in a slot. Created only by fulfilment or credit
 * redemption (milestones 5 to 7); never from the admin panel.
 *
 * @property int $id
 * @property int $slot_id
 * @property int|null $order_id
 * @property int $rider_id
 * @property int $user_id
 * @property int|null $credit_pack_id
 * @property BookingStatus $status
 * @property string $ticket_code
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Slot $slot
 * @property-read Rider $rider
 * @property-read User $user
 * @property-read Order|null $order
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'slot_id',
        'order_id',
        'rider_id',
        'user_id',
        'credit_pack_id',
        'ticket_code',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'confirmed',
    ];

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Rider, $this>
     */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CreditPack, $this>
     */
    public function creditPack(): BelongsTo
    {
        return $this->belongsTo(CreditPack::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
