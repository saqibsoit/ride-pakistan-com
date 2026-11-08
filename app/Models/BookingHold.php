<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\SeatAvailabilityObserver;
use Carbon\CarbonImmutable;
use Database\Factories\BookingHoldFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $slot_id
 * @property int|null $order_id null for a waitlist offer
 * @property int|null $waitlist_entry_id set for a waitlist offer (milestone 10d)
 * @property int $seats
 * @property bool $is_private a private hold takes the whole slot
 * @property CarbonImmutable $expires_at
 * @property-read Slot $slot
 * @property-read Order|null $order
 * @property-read WaitlistEntry|null $waitlistEntry
 */
#[ObservedBy(SeatAvailabilityObserver::class)]
class BookingHold extends Model
{
    /** @use HasFactory<BookingHoldFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'slot_id',
        'order_id',
        'waitlist_entry_id',
        'seats',
        'is_private',
        'expires_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_private' => false,
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
     * @return BelongsTo<WaitlistEntry, $this>
     */
    public function waitlistEntry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class);
    }

    /**
     * @param  Builder<BookingHold>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'is_private' => 'boolean',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
