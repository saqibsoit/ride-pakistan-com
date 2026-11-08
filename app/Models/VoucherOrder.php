<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\VoucherOrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\VoucherOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A gift voucher purchase (milestone 10e). Paid by card or bank transfer
 * through the same verification as orders, but it never holds seats and
 * never goes through FulfilOrder: payment issues one voucher
 * (App\Actions\Vouchers\IssueVoucher). Status changes only through
 * VoucherOrderStateMachine. The message is encrypted; the transfer proof
 * is on the private transfers disk.
 *
 * @property int $id
 * @property string $reference
 * @property int $user_id
 * @property int|null $product_id
 * @property string|null $product_name
 * @property int $amount_minor
 * @property string $currency
 * @property VoucherOrderStatus $status
 * @property PaymentMethod|null $payment_method
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $recipient_name
 * @property string|null $recipient_email
 * @property string|null $message
 * @property CarbonImmutable|null $send_on Pakistan date the voucher is emailed (null = straight away)
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property string|null $screenshot_path
 * @property string|null $transaction_ref
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $proof_deleted_at
 * @property bool $needs_review
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Product|null $product
 * @property-read Voucher|null $voucher
 * @property-read User|null $verifier
 */
class VoucherOrder extends Model
{
    /** @use HasFactory<VoucherOrderFactory> */
    use HasFactory;

    public const PREFIX = 'GV-';

    /** @var list<string> */
    protected $fillable = [
        'reference',
        'user_id',
        'product_id',
        'product_name',
        'amount_minor',
        'currency',
        'recipient_name',
        'recipient_email',
        'message',
        'send_on',
        'expires_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending_payment',
        'currency' => 'PKR',
        'needs_review' => false,
    ];

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function label(): string
    {
        return $this->product_name !== null ? 'Gift voucher: '.$this->product_name : 'Gift voucher';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasOne<Voucher, $this>
     */
    public function voucher(): HasOne
    {
        return $this->hasOne(Voucher::class);
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
            'status' => VoucherOrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount_minor' => 'integer',
            'message' => 'encrypted',
            'send_on' => 'immutable_date',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'proof_deleted_at' => 'immutable_datetime',
            'needs_review' => 'boolean',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
