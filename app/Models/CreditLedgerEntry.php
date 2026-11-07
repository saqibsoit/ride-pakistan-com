<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditReason;
use Database\Factories\CreditLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One credit movement. Append-only.
 *
 * @property int $id
 * @property int $credit_pack_id
 * @property int|null $booking_id
 * @property int $delta
 * @property CreditReason $reason
 * @property string|null $note
 * @property int|null $actor_id
 * @property-read CreditPack $creditPack
 */
class CreditLedgerEntry extends Model
{
    /** @use HasFactory<CreditLedgerEntryFactory> */
    use HasFactory;

    protected $table = 'credit_ledger';

    /** @var list<string> */
    protected $fillable = [
        'credit_pack_id',
        'booking_id',
        'delta',
        'reason',
        'note',
        'actor_id',
    ];

    /**
     * @return BelongsTo<CreditPack, $this>
     */
    public function creditPack(): BelongsTo
    {
        return $this->belongsTo(CreditPack::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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
            'delta' => 'integer',
            'reason' => CreditReason::class,
        ];
    }
}
