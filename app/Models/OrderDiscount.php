<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A discount line on an order, one per kind (promo, referral, voucher,
 * account credit). Calculated on the server and written at checkout from
 * milestone 10e Part B on.
 *
 * @property int $id
 * @property int $order_id
 * @property DiscountKind $kind
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $label
 * @property int $amount_minor
 * @property-read Order $order
 */
class OrderDiscount extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'kind',
        'source_type',
        'source_id',
        'label',
        'amount_minor',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'amount_minor' => 'integer',
        ];
    }
}
