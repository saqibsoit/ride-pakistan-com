<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PrivateRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PrivateSessionRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for a private session at the customer's own date and time
 * (prompt 15B). Created by SubmitPrivateRequest; approved, declined,
 * withdrawn, expired and closed only through PrivateRequestStateMachine.
 * Approval links a private-only slot and the order the customer pays.
 *
 * @property int $id
 * @property string $reference
 * @property int $user_id
 * @property int $product_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $riders
 * @property list<int> $rider_ids
 * @property string|null $note
 * @property PrivateRequestStatus $status
 * @property CarbonImmutable $answer_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decline_reason
 * @property string|null $suggested_times
 * @property int|null $slot_id
 * @property int|null $order_id
 * @property int|null $trainer_id
 * @property CarbonImmutable|null $pay_by
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Product $product
 * @property-read Slot|null $slot
 * @property-read Order|null $order
 * @property-read User|null $trainer
 * @property-read User|null $decidedBy
 */
class PrivateSessionRequest extends Model
{
    /** @use HasFactory<PrivateSessionRequestFactory> */
    use HasFactory;

    public const NOTE_MAX_LENGTH = 500;

    /**
     * Status, decision, slot, order and deadlines are not fillable: they
     * change only through the state machine and the approval.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'user_id',
        'product_id',
        'starts_at',
        'ends_at',
        'riders',
        'rider_ids',
        'note',
        'answer_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'requested',
    ];

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
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

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
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Requested or approved (waiting for payment).
     *
     * @param  Builder<PrivateSessionRequest>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', PrivateRequestStatus::openValues());
    }

    /** "Sat 14 Nov 2026, 10:00–12:00" in Pakistan time. */
    public function label(): string
    {
        $tz = Slot::displayTimezone();

        return $this->starts_at->setTimezone($tz)->format('D j M Y, H:i').'–'.$this->ends_at->setTimezone($tz)->format('H:i');
    }

    public static function localTime(?CarbonImmutable $at): string
    {
        return $at === null ? '' : $at->setTimezone(Slot::displayTimezone())->format('D j M, H:i');
    }

    /** The customer-facing status line. */
    public function statusLine(): string
    {
        return match ($this->status) {
            PrivateRequestStatus::Requested => 'Requested: we’ll answer by '.self::localTime($this->answer_by),
            PrivateRequestStatus::Approved => $this->order?->status === OrderStatus::AwaitingVerification
                ? 'Approved: transfer received, being checked'
                : 'Approved: pay by '.self::localTime($this->pay_by),
            default => $this->status->label(),
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'riders' => 'integer',
            'rider_ids' => 'array',
            'note' => 'encrypted',
            'status' => PrivateRequestStatus::class,
            'answer_by' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'pay_by' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
