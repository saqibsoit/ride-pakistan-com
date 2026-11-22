<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An order for one product, or for one event (decided 2026-11-22), PLAN
 * §3. Amounts in paisa. The status is not fillable: it changes only
 * through App\Actions\Orders\OrderStateMachine.
 *
 * Event orders with a deposit: amount_minor is still what is charged at
 * checkout (the deposit, after discounts); balance_minor is the rest,
 * which FulfilOrder turns into an OrderBalance row.
 *
 * @property int $id
 * @property string $reference
 * @property int $user_id
 * @property int|null $product_id null for an event order
 * @property int|null $ride_event_id set for an event order
 * @property int|null $deposit_percent event orders: the deposit percentage as booked (null = full payment)
 * @property int $balance_minor event orders: what is left to pay after the deposit
 * @property array<string, string>|null $answers event orders: per-booking question answers (encrypted)
 * @property OrderStatus $status
 * @property PaymentMethod|null $payment_method
 * @property int $subtotal_minor price before discounts (milestone 10e Part B)
 * @property int $amount_minor what the customer pays by card or transfer, after discounts
 * @property int $amount_refunded_minor
 * @property string $currency
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable $created_at
 * @property string|null $product_name snapshot at order time
 * @property ProductType|null $product_type snapshot at order time
 * @property int|null $sessions_count snapshot at order time
 * @property int|null $validity_days snapshot at order time
 * @property string|null $contact_name guest checkout: the name typed for this record
 * @property string|null $contact_whatsapp guest checkout: the WhatsApp number typed (E.164)
 * @property bool $placed_as_guest placed signed out (guest checkout)
 * @property int $manage_link_version part of every signed manage link; raised to revoke them
 * @property-read User $user
 * @property-read Product|null $product
 * @property-read RideEvent|null $rideEvent
 * @property-read OrderBalance|null $balance
 * @property-read Collection<int, OrderDiscount> $discounts
 * @property-read PromoRedemption|null $promoRedemption
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
        'ride_event_id',
        'deposit_percent',
        'balance_minor',
        'answers',
        'payment_method',
        'subtotal_minor',
        'amount_minor',
        'currency',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'expires_at',
        'meta',
        'contact_name',
        'contact_whatsapp',
        'placed_as_guest',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending_payment',
        'currency' => 'PKR',
        'amount_refunded_minor' => 0,
        'balance_minor' => 0,
        'placed_as_guest' => false,
        'manage_link_version' => 1,
    ];

    /**
     * Freeze what was bought when the order is placed (milestone 10a):
     * name, type, sessions and validity. Later product edits never change
     * this order or the credit pack it grants. Not mass assignable; only
     * set here, from the product as it is at this moment.
     */
    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            // Before any discount the order costs its subtotal (milestone 10e Part B).
            $order->subtotal_minor ??= $order->amount_minor;

            if ($order->product_name !== null) {
                return;
            }

            if ($order->ride_event_id !== null) {
                $event = RideEvent::query()->find($order->ride_event_id);
                $order->product_name = $event !== null ? $event->title : null;
                $order->product_type = ProductType::Event;

                return;
            }

            $product = Product::query()->find($order->product_id);

            if ($product === null) {
                return;
            }

            $order->product_name = $product->name;
            $order->product_type = $product->type;
            $order->sessions_count = $product->sessions_count;
            $order->validity_days = $product->validity_days;
        });
    }

    /** Product (or event) name as bought. */
    public function productName(): string
    {
        return $this->product_name ?? $this->product->name ?? $this->rideEvent->title ?? '';
    }

    /** Product type as bought (private vs group vs package vs event). */
    public function productType(): ProductType
    {
        if ($this->product_type !== null) {
            return $this->product_type;
        }

        return $this->product !== null ? $this->product->type : ProductType::Event;
    }

    /** Credits a package order grants, as bought. */
    public function sessionsCount(): int
    {
        return $this->sessions_count ?? $this->product->sessions_count ?? 0;
    }

    /** Package validity in days from confirmation, as bought. */
    public function validityDays(): ?int
    {
        return $this->product_type !== null ? $this->validity_days : $this->product?->validity_days;
    }

    /** An event booking (decided 2026-11-22). */
    public function isEvent(): bool
    {
        return $this->ride_event_id !== null;
    }

    /** The price after discounts: what is charged at checkout plus any balance. */
    public function priceMinor(): int
    {
        return $this->amount_minor + $this->balance_minor;
    }

    /**
     * @return BelongsTo<RideEvent, $this>
     */
    public function rideEvent(): BelongsTo
    {
        return $this->belongsTo(RideEvent::class);
    }

    /**
     * @return HasOne<OrderBalance, $this>
     */
    public function balance(): HasOne
    {
        return $this->hasOne(OrderBalance::class);
    }

    public function isPackage(): bool
    {
        return $this->productType() === ProductType::Package;
    }

    public function isPrivate(): bool
    {
        return $this->productType() === ProductType::Private;
    }

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
     * Discount lines, one per kind (milestone 10e Part B).
     *
     * @return HasMany<OrderDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class)->orderBy('id');
    }

    /**
     * @return HasOne<PromoRedemption, $this>
     */
    public function promoRedemption(): HasOne
    {
        return $this->hasOne(PromoRedemption::class);
    }

    /** Total of the discount lines. */
    public function discountMinor(): int
    {
        return $this->subtotal_minor - $this->amount_minor - $this->balance_minor;
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
    /**
     * The WhatsApp number for this booking: the one typed at a guest
     * checkout (guest checkout keeps an existing account's profile as it
     * is), otherwise the account's.
     */
    public function contactWhatsApp(): ?string
    {
        return $this->contact_whatsapp ?? $this->user->whatsapp_phone ?? null;
    }

    protected function casts(): array
    {
        return [
            'placed_as_guest' => 'boolean',
            'manage_link_version' => 'integer',
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'product_type' => ProductType::class,
            'sessions_count' => 'integer',
            'validity_days' => 'integer',
            'subtotal_minor' => 'integer',
            'amount_minor' => 'integer',
            'amount_refunded_minor' => 'integer',
            'deposit_percent' => 'integer',
            'balance_minor' => 'integer',
            'answers' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
