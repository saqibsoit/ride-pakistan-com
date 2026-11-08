<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Observers\SeatAvailabilityObserver;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
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
 * @property bool $is_private part of a private session (blocks the slot)
 * @property BookingStatus $status
 * @property string $ticket_code
 * @property int $calendar_sequence iCalendar SEQUENCE, raised on every move (milestone 10d)
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Slot $slot
 * @property int|null $horse_id set through AssignHorse
 * @property string|null $session_note trainer's note for this rider (encrypted, WriteSessionNote)
 * @property-read Horse|null $horse
 * @property-read Rider $rider
 * @property-read User $user
 * @property-read Order|null $order
 */
#[ObservedBy(SeatAvailabilityObserver::class)]
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
        'is_private',
        'ticket_code',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'confirmed',
        'is_private' => false,
        'calendar_sequence' => 0,
    ];

    /** Paid for with a package credit (customers may change these themselves). */
    public function usesCredit(): bool
    {
        return $this->credit_pack_id !== null;
    }

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
     * @return BelongsTo<Horse, $this>
     */
    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
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
     * Ordered by slot start time (soonest first).
     *
     * @param  Builder<self>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->select('bookings.*')
            ->join('slots', 'slots.id', '=', 'bookings.slot_id')
            ->where('slots.starts_at', '>', now())
            ->orderBy('slots.starts_at');
    }

    /**
     * Ordered by slot start time (most recent first).
     *
     * @param  Builder<self>  $query
     */
    public function scopePast(Builder $query): void
    {
        $query->select('bookings.*')
            ->join('slots', 'slots.id', '=', 'bookings.slot_id')
            ->where('slots.starts_at', '<=', now())
            ->orderByDesc('slots.starts_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'is_private' => 'boolean',
            'calendar_sequence' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'session_note' => 'encrypted',
        ];
    }
}
