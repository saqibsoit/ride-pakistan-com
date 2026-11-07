<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditPackStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CreditPackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Package credits (PLAN §2): granted only when the order is paid, valid
 * from confirmation, unusable once expired.
 *
 * @property int $id
 * @property int $user_id
 * @property int $order_id
 * @property int $credits_total
 * @property int $credits_used
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable $expires_at
 * @property CreditPackStatus $status
 * @property-read User $user
 * @property-read Order $order
 */
class CreditPack extends Model
{
    /** @use HasFactory<CreditPackFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'order_id',
        'credits_total',
        'valid_from',
        'expires_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'credits_used' => 0,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<CreditLedgerEntry, $this>
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function creditsRemaining(): int
    {
        return max(0, $this->credits_total - $this->credits_used);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits_total' => 'integer',
            'credits_used' => 'integer',
            'valid_from' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'status' => CreditPackStatus::class,
        ];
    }
}
