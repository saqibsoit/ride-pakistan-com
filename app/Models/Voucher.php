<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VoucherStatus;
use Carbon\CarbonImmutable;
use Database\Factories\VoucherFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An issued gift voucher (milestone 10e): a PKR balance with a unique
 * code, valid until expires_at. Created only by IssueVoucher; the balance
 * and status change only through VoucherBalance, which writes the ledger.
 *
 * @property int $id
 * @property string $code 12 unambiguous characters, shown as XXXX-XXXX-XXXX
 * @property int|null $voucher_order_id null when an admin issued it
 * @property int|null $issued_by
 * @property string|null $issue_reason
 * @property string|null $label
 * @property string|null $recipient_name
 * @property string|null $recipient_email
 * @property int $initial_minor
 * @property int $balance_minor
 * @property string $currency
 * @property CarbonImmutable $expires_at
 * @property VoucherStatus $status
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property-read VoucherOrder|null $voucherOrder
 * @property-read User|null $issuer
 */
class Voucher extends Model
{
    /** @use HasFactory<VoucherFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'voucher_order_id',
        'issued_by',
        'issue_reason',
        'label',
        'recipient_name',
        'recipient_email',
        'initial_minor',
        'balance_minor',
        'currency',
        'expires_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'currency' => 'PKR',
    ];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** XXXX-XXXX-XXXX, easier to read out and type. */
    public function formattedCode(): string
    {
        return implode('-', str_split($this->code, 4));
    }

    /** Accepts the code with or without dashes and spaces, any case. */
    public static function normaliseCode(string $input): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    }

    public function isUsable(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $this->status === VoucherStatus::Active && $this->balance_minor > 0 && $this->expires_at->isAfter($at);
    }

    /**
     * @return BelongsTo<VoucherOrder, $this>
     */
    public function voucherOrder(): BelongsTo
    {
        return $this->belongsTo(VoucherOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return HasMany<VoucherLedgerEntry, $this>
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(VoucherLedgerEntry::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VoucherStatus::class,
            'initial_minor' => 'integer',
            'balance_minor' => 'integer',
            'expires_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
