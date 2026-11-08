<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SlotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A concrete session (UTC times). Rule slots come from GenerateSlots;
 * manual slots from CreateManualSlot and are never touched by the
 * generator. Status changes go through ChangeSlotStatus.
 *
 * @property int $id
 * @property int|null $schedule_rule_id
 * @property SlotSource $source
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $capacity
 * @property SlotStatus $status
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property int|null $seats_booked from withSeatCounts()
 * @property int|null $seats_held from withSeatCounts()
 * @property-read ScheduleRule|null $scheduleRule
 */
class Slot extends Model
{
    /** @use HasFactory<SlotFactory> */
    use HasFactory;

    /**
     * Status and cancellation fields are not fillable: they change only
     * through ChangeSlotStatus. Source is set once, by the code that
     * creates the slot (GenerateSlots or CreateManualSlot).
     *
     * @var list<string>
     */
    protected $fillable = [
        'schedule_rule_id',
        'starts_at',
        'ends_at',
        'capacity',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'source' => 'rule',
    ];

    public function isManual(): bool
    {
        return $this->source === SlotSource::Manual;
    }

    /**
     * @return BelongsTo<ScheduleRule, $this>
     */
    public function scheduleRule(): BelongsTo
    {
        return $this->belongsTo(ScheduleRule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<BookingHold, $this>
     */
    public function holds(): HasMany
    {
        return $this->hasMany(BookingHold::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Adds seats_booked (bookings that occupy a seat) and seats_held
     * (unexpired holds) as subquery columns.
     *
     * @param  Builder<Slot>  $query
     */
    public function scopeWithSeatCounts(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('slots.*');
        }

        $query
            ->selectSub(fn (QueryBuilder $q) => $q->from('bookings')
                ->selectRaw('count(*)')
                ->whereColumn('bookings.slot_id', 'slots.id')
                ->whereIn('bookings.status', BookingStatus::seatOccupyingValues()), 'seats_booked')
            ->selectSub(fn (QueryBuilder $q) => $q->from('booking_holds')
                ->selectRaw('coalesce(sum(seats), 0)')
                ->whereColumn('booking_holds.slot_id', 'slots.id')
                ->where('booking_holds.expires_at', '>', now()), 'seats_held');
    }

    /**
     * @param  Builder<Slot>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('starts_at', '>', now());
    }

    /**
     * Slots with anything attached (any booking, hold or order line). The
     * generator never changes or deletes these.
     *
     * @param  Builder<Slot>  $query
     */
    public function scopeInUse(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereHas('bookings')
            ->orWhereHas('holds')
            ->orWhereHas('orderItems'));
    }

    public function seatsBooked(): int
    {
        return $this->seats_booked ?? $this->bookings()
            ->whereIn('status', BookingStatus::seatOccupyingValues())
            ->count();
    }

    public function seatsHeld(): int
    {
        return $this->seats_held ?? (int) $this->holds()->where('expires_at', '>', now())->sum('seats');
    }

    public function seatsFree(): int
    {
        return max(0, $this->capacity - $this->seatsBooked() - $this->seatsHeld());
    }

    public function isInUse(): bool
    {
        return $this->bookings()->exists() || $this->holds()->exists() || $this->orderItems()->exists();
    }

    public function localStartsAt(): CarbonImmutable
    {
        return $this->starts_at->setTimezone(self::displayTimezone());
    }

    public function localEndsAt(): CarbonImmutable
    {
        return $this->ends_at->setTimezone(self::displayTimezone());
    }

    /** "Sat 14 Nov 2026, 08:00–10:00" in Pakistan time. */
    public function label(): string
    {
        return $this->localStartsAt()->format('D j M Y, H:i').'–'.$this->localEndsAt()->format('H:i');
    }

    public static function displayTimezone(): string
    {
        return (string) config('app.display_timezone');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'status' => SlotStatus::class,
            'source' => SlotSource::class,
            'cancelled_at' => 'immutable_datetime',
            // Subquery columns from withSeatCounts().
            'seats_booked' => 'integer',
            'seats_held' => 'integer',
        ];
    }
}
