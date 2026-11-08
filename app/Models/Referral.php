<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReferralStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ReferralFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A new customer who signed up through another customer's referral link
 * (milestone 10e). One per referred customer. The referrer earns account
 * credit once, when a paid booking of the referred customer is attended.
 *
 * @property int $id
 * @property int $referrer_id
 * @property int $referred_id
 * @property ReferralStatus $status
 * @property string|null $rejection_reason
 * @property string|null $signup_email
 * @property string|null $signup_phone
 * @property int|null $rewarded_booking_id
 * @property CarbonImmutable|null $rewarded_at
 * @property CarbonImmutable $created_at
 * @property-read User $referrer
 * @property-read User $referred
 * @property-read Booking|null $rewardedBooking
 */
class Referral extends Model
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'signup_email',
        'signup_phone',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function rewardedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'rewarded_booking_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'rewarded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
