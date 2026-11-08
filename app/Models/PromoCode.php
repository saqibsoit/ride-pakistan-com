<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-made promo code (milestone 10e). Percentage (1–100) or a fixed
 * PKR amount; no products selected means every product. One code per
 * order, never stacked with another promo (it can combine with a gift
 * voucher). Saved only through SavePromoCode; a used code is archived,
 * never deleted.
 *
 * @property int $id
 * @property string $code
 * @property string|null $description
 * @property DiscountType $discount_type
 * @property int|null $percent
 * @property int|null $amount_minor
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $max_uses
 * @property int|null $max_uses_per_customer
 * @property int|null $min_order_minor
 * @property bool $first_booking_only
 * @property bool $is_active
 * @property CarbonImmutable|null $archived_at
 * @property int|null $redemptions_count
 */
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'description',
        'discount_type',
        'percent',
        'amount_minor',
        'starts_at',
        'ends_at',
        'max_uses',
        'max_uses_per_customer',
        'min_order_minor',
        'first_booking_only',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'first_booking_only' => false,
        'is_active' => true,
    ];

    public static function normaliseCode(string $input): string
    {
        return strtoupper(trim($input));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Human summary, e.g. "10% off" or "PKR 2,000 off". */
    public function summary(): string
    {
        return $this->discount_type === DiscountType::Percent
            ? $this->percent.'% off'
            : Money::format((int) $this->amount_minor).' off';
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * @return HasMany<PromoRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'percent' => 'integer',
            'amount_minor' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'max_uses' => 'integer',
            'max_uses_per_customer' => 'integer',
            'min_order_minor' => 'integer',
            'first_booking_only' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
