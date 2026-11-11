<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Observers\SeatAvailabilityObserver;
use Carbon\CarbonImmutable;
use Database\Factories\SlotFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property bool $private_only only private sessions can book it (manual slots)
 * @property SlotStatus $status
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property int|null $seats_booked from withSeatCounts()
 * @property int|null $seats_held from withSeatCounts()
 * @property bool|null $has_private from withSeatCounts(): a private booking or hold blocks the slot
 * @property int|null $trainer_id who runs the session (milestone 10b); trainers see only their own
 * @property CarbonImmutable|null $replaces_starts_at a rule start this slot replaced when the bulk editor moved it (prompt 15A)
 * @property-read ScheduleRule|null $scheduleRule
 * @property-read User|null $trainer
 * @property-read SlotWeather|null $weather
 */
#[ObservedBy(SeatAvailabilityObserver::class)]
class Slot extends Model
{
    /** @use HasFactory<SlotFactory> */
    use HasFactory;

    /** Weather alerts and badges cover sessions starting this many hours ahead (milestone 10f). */
    public const WEATHER_WINDOW_HOURS = 48;

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
        'private_only',
        'trainer_id',
        'replaces_starts_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'source' => 'rule',
        'private_only' => false,
    ];

    public function isManual(): bool
    {
        return $this->source === SlotSource::Manual;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
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
     * Latest forecast assessment (milestone 10f), for the next 48 hours.
     *
     * @return HasOne<SlotWeather, $this>
     */
    public function weather(): HasOne
    {
        return $this->hasOne(SlotWeather::class);
    }

    /**
     * The forecast badge, if the slot is within the alert window (starts in
     * the next 48 hours, not yet ended, not cancelled) and has been assessed.
     */
    public function currentWeather(): ?SlotWeather
    {
        if ($this->status === SlotStatus::Cancelled
            || $this->ends_at->lessThanOrEqualTo(now())
            || $this->starts_at->greaterThan(now()->addHours(self::WEATHER_WINDOW_HOURS))) {
            return null;
        }

        return $this->weather;
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
     * Waitlist for this slot (milestone 10d), first in line first.
     *
     * @return HasMany<WaitlistEntry, $this>
     */
    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class)->orderBy('id');
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
                ->where('booking_holds.expires_at', '>', now()), 'seats_held')
            ->selectSub(fn (QueryBuilder $q) => $q->selectRaw('exists('.
                'select 1 from bookings where bookings.slot_id = slots.id and bookings.is_private = 1 and bookings.status in ('.
                implode(',', array_map(fn (string $v): string => "'".$v."'", BookingStatus::seatOccupyingValues())).')'.
                ' union all '.
                'select 1 from booking_holds where booking_holds.slot_id = slots.id and booking_holds.is_private = 1 and booking_holds.expires_at > ?'.
                ')', [now()]), 'has_private');
    }

    /**
     * Open and starting after now plus the minimum notice (rule and manual
     * slots alike). Seats are checked separately.
     *
     * @param  Builder<Slot>  $query
     */
    public function scopeBookable(Builder $query, int $minNoticeHours = 0): void
    {
        $query->where('slots.status', SlotStatus::Open)
            ->where('slots.starts_at', '>', now()->addHours(max(0, $minNoticeHours)));
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

    /** A private booking or an active private hold takes the whole slot. */
    public function isPrivatelyBooked(): bool
    {
        if ($this->has_private !== null) {
            return $this->has_private;
        }

        return $this->bookings()->where('is_private', true)->whereIn('status', BookingStatus::seatOccupyingValues())->exists()
            || $this->holds()->where('is_private', true)->where('expires_at', '>', now())->exists();
    }

    public function seatsFree(): int
    {
        if ($this->isPrivatelyBooked()) {
            return 0;
        }

        return max(0, $this->capacity - $this->seatsBooked() - $this->seatsHeld());
    }

    /** Nothing occupies the slot: a private session can take it. */
    public function isEmpty(): bool
    {
        return ! $this->isPrivatelyBooked() && $this->seatsBooked() === 0 && $this->seatsHeld() === 0;
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
            'private_only' => 'boolean',
            'status' => SlotStatus::class,
            'source' => SlotSource::class,
            'cancelled_at' => 'immutable_datetime',
            'replaces_starts_at' => 'immutable_datetime',
            // Subquery columns from withSeatCounts().
            'seats_booked' => 'integer',
            'seats_held' => 'integer',
            'has_private' => 'boolean',
        ];
    }
}
