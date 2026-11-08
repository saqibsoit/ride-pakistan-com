<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountCreditReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed change to a customer's PKR account credit (milestone 10e).
 * The balance is the sum of a customer's entries (AccountCredit::balance).
 *
 * @property int $id
 * @property int $user_id
 * @property int $delta_minor
 * @property AccountCreditReason $reason
 * @property int|null $referral_id
 * @property int|null $order_id
 * @property string|null $note
 * @property int|null $actor_id
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read User|null $actor
 * @property-read Referral|null $referral
 */
class AccountCreditEntry extends Model
{
    protected $table = 'account_credit_ledger';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'delta_minor',
        'reason',
        'referral_id',
        'order_id',
        'note',
        'actor_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Referral, $this>
     */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => AccountCreditReason::class,
            'delta_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
