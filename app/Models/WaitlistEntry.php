<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WaitlistStatus;
use Carbon\CarbonImmutable;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer waiting for seats in a full slot (milestone 10d). Created by
 * JoinWaitlist; offers by OfferWaitlistSeats; status changes only through
 * ChangeWaitlistStatus.
 *
 * @property int $id
 * @property int $slot_id
 * @property int $user_id
 * @property int $seats riders they want to bring
 * @property WaitlistStatus $status
 * @property CarbonImmutable|null $offered_at
 * @property CarbonImmutable|null $offer_expires_at
 * @property CarbonImmutable|null $booked_at
 * @property string|null $closed_reason
 * @property CarbonImmutable $created_at
 * @property-read Slot $slot
 * @property-read User $user
 * @property-read BookingHold|null $hold
 */
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'slot_id',
        'user_id',
        'seats',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'waiting',
    ];

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The seats held for an offer.
     *
     * @return HasOne<BookingHold, $this>
     */
    public function hold(): HasOne
    {
        return $this->hasOne(BookingHold::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', WaitlistStatus::activeValues());
    }

    /** Place in line among the slot's waiting and offered entries (1 = next). */
    public function position(): int
    {
        return self::query()
            ->where('slot_id', $this->slot_id)
            ->active()
            ->where('id', '<=', $this->id)
            ->count();
    }

    /** Seats an unexpired offer holds for this customer in this slot (the booking flow counts them as free). */
    public static function offeredSeats(User $user, int $slotId): int
    {
        return (int) self::query()
            ->where('user_id', $user->id)
            ->where('slot_id', $slotId)
            ->where('status', WaitlistStatus::Offered)
            ->where('offer_expires_at', '>', now())
            ->sum('seats');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'status' => WaitlistStatus::class,
            'offered_at' => 'immutable_datetime',
            'offer_expires_at' => 'immutable_datetime',
            'booked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
