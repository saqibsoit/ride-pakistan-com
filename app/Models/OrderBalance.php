<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BalanceMethod;
use App\Enums\BalanceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What is still owed on a deposit event order (decided 2026-11-22),
 * created by FulfilOrder when the deposit confirms the booking. Paid
 * online from the manage page (HandleBalanceStripeEvent) or recorded by an
 * admin (MarkBalancePaid). The status is not fillable: it changes only in
 * those actions and in the event cancellation and refund actions.
 *
 * @property int $id
 * @property int $order_id
 * @property int $amount_minor
 * @property int $refunded_minor
 * @property string $currency
 * @property CarbonImmutable $due_at when it is due (the start, or the online deadline)
 * @property CarbonImmutable $online_until online payment closes then
 * @property BalanceStatus $status
 * @property BalanceMethod|null $method
 * @property CarbonImmutable|null $paid_at
 * @property int|null $recorded_by
 * @property string|null $reference
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property int $stripe_attempts
 * @property-read Order $order
 * @property-read User|null $recorder
 */
class OrderBalance extends Model
{
    /** @use HasFactory<OrderBalanceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['order_id', 'amount_minor', 'currency', 'due_at', 'online_until'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'due',
        'currency' => 'PKR',
        'refunded_minor' => 0,
        'stripe_attempts' => 0,
    ];

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
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isDue(): bool
    {
        return $this->status === BalanceStatus::Due;
    }

    public function isPaid(): bool
    {
        return $this->status === BalanceStatus::Paid;
    }

    /** Money received for the balance, net of refunds. */
    public function receivedMinor(): int
    {
        return in_array($this->status, [BalanceStatus::Paid, BalanceStatus::Refunded], true)
            ? max(0, $this->amount_minor - $this->refunded_minor)
            : 0;
    }

    public function canPayOnline(?CarbonImmutable $now = null): bool
    {
        return $this->isDue() && ($now ?? CarbonImmutable::now())->isBefore($this->online_until);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'refunded_minor' => 'integer',
            'due_at' => 'immutable_datetime',
            'online_until' => 'immutable_datetime',
            'status' => BalanceStatus::class,
            'method' => BalanceMethod::class,
            'paid_at' => 'immutable_datetime',
            'stripe_attempts' => 'integer',
        ];
    }
}
