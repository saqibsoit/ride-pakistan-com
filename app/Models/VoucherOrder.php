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
 * @property string|null $contact_name guest checkout: the name typed for this record
 * @property string|null $contact_whatsapp guest checkout: the WhatsApp number typed (E.164)
 * @property bool $placed_as_guest placed signed out (guest checkout)
 * @property int $manage_link_version part of every signed manage link; raised to revoke them
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
        'contact_name',
        'contact_whatsapp',
        'placed_as_guest',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending_payment',
        'currency' => 'PKR',
        'needs_review' => false,
        'placed_as_guest' => false,
        'manage_link_version' => 1,
    ];

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * Who the voucher is from: the name typed at a guest purchase (guest
     * checkout), never the name of an account that already used the email.
     */
    public function buyerName(): ?string
    {
        $name = $this->placed_as_guest ? $this->contact_name : ($this->user->name ?? null);

        return $name === null || trim($name) === '' ? null : $name;
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
     * Money actually received for this purchase (milestone 10e): what Stripe
     * charged, which can differ from the price on a mismatch, or the price
     * for an approved bank transfer. 0 before any money came in.
     */
    public function receivedMinor(): int
    {
        if (isset($this->meta['amount_received_minor'])) {
            return (int) $this->meta['amount_received_minor'];
        }

        return $this->paid_at === null ? 0 : $this->amount_minor;
    }

    public function refundedMinor(): int
    {
        return (int) ($this->meta['amount_refunded_minor'] ?? 0);
    }

    /**
     * Refunds recorded on this purchase, oldest first: Stripe (automatic or
     * dashboard) and bank refunds the team made.
     *
     * @return list<array{provider: string, provider_id: string|null, amount_minor: int, on: string}>
     */
    public function refundEntries(): array
    {
        $entries = [];

        foreach ((array) ($this->meta['payments'] ?? []) as $payment) {
            if (is_array($payment) && ($payment['status'] ?? null) === 'refunded') {
                $entries[] = [
                    'provider' => (string) ($payment['provider'] ?? ''),
                    'provider_id' => isset($payment['provider_id']) ? (string) $payment['provider_id'] : null,
                    'amount_minor' => (int) ($payment['amount_minor'] ?? 0),
                    'on' => (string) ($payment['transferred_on'] ?? substr((string) ($payment['at'] ?? ''), 0, 10)),
                ];
            }
        }

        return $entries;
    }

    /**
     * Books a refund on the purchase: a refund entry in meta "payments" and
     * the refunded total. The caller holds the purchase row lock.
     *
     * @param  array<string, scalar|null>  $extra
     */
    public function addRefund(string $provider, ?string $providerId, int $amountMinor, array $extra = []): void
    {
        $meta = $this->meta ?? [];
        $payments = is_array($meta['payments'] ?? null) ? $meta['payments'] : [];
        $payments[] = [
            'provider' => $provider,
            'provider_id' => $providerId,
            'amount_minor' => $amountMinor,
            'currency' => $this->currency,
            'status' => 'refunded',
            'at' => CarbonImmutable::now()->toIso8601String(),
            ...$extra,
        ];
        $meta['payments'] = $payments;
        $meta['amount_refunded_minor'] = (int) ($meta['amount_refunded_minor'] ?? 0) + $amountMinor;
        $this->meta = $meta;
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placed_as_guest' => 'boolean',
            'manage_link_version' => 'integer',
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
