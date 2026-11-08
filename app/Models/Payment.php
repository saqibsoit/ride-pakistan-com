<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property PaymentProvider $provider
 * @property string|null $provider_id
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentStatus $status
 * @property CarbonImmutable|null $transferred_on bank refunds: the Pakistan date the outgoing transfer was made
 * @property array<string, mixed>|null $raw
 * @property-read Order $order
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'provider',
        'provider_id',
        'amount_minor',
        'currency',
        'status',
        'transferred_on',
        'raw',
    ];

    /**
     * Raw provider payloads can hold customer details.
     *
     * @var list<string>
     */
    protected $hidden = [
        'raw',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'transferred_on' => 'immutable_date',
            'raw' => 'array',
        ];
    }
}
