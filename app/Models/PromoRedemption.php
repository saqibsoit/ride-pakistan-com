<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a promo code on an order (one code per order). Written at
 * checkout from milestone 10e Part B on.
 *
 * @property int $id
 * @property int $promo_code_id
 * @property int $order_id
 * @property int $user_id
 * @property int $amount_minor
 * @property CarbonImmutable $created_at
 * @property-read PromoCode $promoCode
 * @property-read Order $order
 * @property-read User $user
 */
class PromoRedemption extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'promo_code_id',
        'order_id',
        'user_id',
        'amount_minor',
    ];

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
