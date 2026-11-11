<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\SlotChangeResponse;
use Carbon\CarbonImmutable;
use Database\Factories\SlotChangeNoticeFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slot's time changed in the bulk schedule editor (prompt 15A) and this
 * customer had confirmed bookings in it. Created by ApplyBulkSchedule;
 * answered once through RespondToSlotChange from the email's signed links,
 * which expire at the new start.
 *
 * @property int $id
 * @property string $batch
 * @property int $slot_id
 * @property int $user_id
 * @property CarbonImmutable $old_starts_at
 * @property CarbonImmutable $old_ends_at
 * @property CarbonImmutable $new_starts_at
 * @property CarbonImmutable $new_ends_at
 * @property SlotChangeResponse $status
 * @property CarbonImmutable|null $responded_at
 * @property-read Slot $slot
 * @property-read User $user
 */
class SlotChangeNotice extends Model
{
    /** @use HasFactory<SlotChangeNoticeFactory> */
    use HasFactory;

    /**
     * Status and responded_at change only through RespondToSlotChange.
     *
     * @var list<string>
     */
    protected $fillable = [
        'batch',
        'slot_id',
        'user_id',
        'old_starts_at',
        'old_ends_at',
        'new_starts_at',
        'new_ends_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
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
     * The customer's confirmed bookings still in the changed slot.
     *
     * @return Collection<int, Booking>
     */
    public function bookings(): Collection
    {
        return Booking::query()
            ->where('slot_id', $this->slot_id)
            ->where('user_id', $this->user_id)
            ->where('status', BookingStatus::Confirmed)
            ->with(['rider', 'order', 'creditPack'])
            ->orderBy('id')
            ->get();
    }

    /** The links work until the session starts at its new time. */
    public function isOpen(?CarbonImmutable $now = null): bool
    {
        return $this->status === SlotChangeResponse::Pending
            && $this->new_starts_at->isAfter($now ?? CarbonImmutable::now());
    }

    public function oldLabel(): string
    {
        $tz = Slot::displayTimezone();

        return $this->old_starts_at->setTimezone($tz)->format('D j M Y, H:i').'–'.$this->old_ends_at->setTimezone($tz)->format('H:i');
    }

    public function newLabel(): string
    {
        $tz = Slot::displayTimezone();

        return $this->new_starts_at->setTimezone($tz)->format('D j M Y, H:i').'–'.$this->new_ends_at->setTimezone($tz)->format('H:i');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_starts_at' => 'immutable_datetime',
            'old_ends_at' => 'immutable_datetime',
            'new_starts_at' => 'immutable_datetime',
            'new_ends_at' => 'immutable_datetime',
            'status' => SlotChangeResponse::class,
            'responded_at' => 'immutable_datetime',
        ];
    }
}
