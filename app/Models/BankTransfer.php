<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankTransferStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BankTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's transfer proof (PLAN §3.2). The screenshot lives on the
 * private disk and is only ever served through short-lived signed admin URLs.
 *
 * @property int $id
 * @property int $order_id
 * @property string $screenshot_path
 * @property string|null $transaction_ref
 * @property CarbonImmutable $submitted_at
 * @property BankTransferStatus $status
 * @property int|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property string|null $rejection_reason
 * @property-read Order $order
 */
class BankTransfer extends Model
{
    /** @use HasFactory<BankTransferFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'screenshot_path',
        'transaction_ref',
        'submitted_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'screenshot_path',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'submitted',
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
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'status' => BankTransferStatus::class,
            'verified_at' => 'immutable_datetime',
        ];
    }
}
