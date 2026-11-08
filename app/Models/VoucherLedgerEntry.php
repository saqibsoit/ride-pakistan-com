<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VoucherLedgerReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed change to a voucher balance (milestone 10e).
 *
 * @property int $id
 * @property int $voucher_id
 * @property int|null $order_id
 * @property int $delta_minor
 * @property VoucherLedgerReason $reason
 * @property string|null $note
 * @property int|null $actor_id
 * @property CarbonImmutable $created_at
 * @property-read Voucher $voucher
 * @property-read User|null $actor
 */
class VoucherLedgerEntry extends Model
{
    protected $table = 'voucher_ledger';

    /** @var list<string> */
    protected $fillable = [
        'voucher_id',
        'order_id',
        'delta_minor',
        'reason',
        'note',
        'actor_id',
    ];

    /**
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => VoucherLedgerReason::class,
            'delta_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
