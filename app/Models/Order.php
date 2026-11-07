<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An order for one product (PLAN §3). Amounts in paisa. The status is not
 * fillable: it changes only through App\Actions\Orders\OrderStateMachine.
 *
 * @property int $id
 * @property string $reference
 * @property int $user_id
 * @property int $product_id
 * @property OrderStatus $status
 * @property PaymentMethod|null $payment_method
 * @property int $amount_minor
 * @property int $amount_refunded_minor
 * @property string $currency
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Product $product
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'reference',
        'user_id',
        'product_id',
        'payment_method',
        'amount_minor',
        'currency',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'expires_at',
        'meta',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending_payment',
        'currency' => 'PKR',
        'amount_refunded_minor' => 0,
    ];

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<BookingHold, $this>
     */
    public function holds(): HasMany
    {
        return $this->hasMany(BookingHold::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<BankTransfer, $this>
     */
    public function bankTransfers(): HasMany
    {
        return $this->hasMany(BankTransfer::class);
    }

    /**
     * @return HasOne<CreditPack, $this>
     */
    public function creditPack(): HasOne
    {
        return $this->hasOne(CreditPack::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount_minor' => 'integer',
            'amount_refunded_minor' => 'integer',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
