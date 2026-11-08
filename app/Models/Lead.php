<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadInterest;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Concerns\AuditsAdminChanges;
use Carbon\CarbonImmutable;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales lead (PLAN §5). Web forms capture it with first-touch
 * attribution; the team adds Instagram DMs and calls by hand. Status
 * changes only through ChangeLeadStatus.
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $instagram_handle
 * @property LeadInterest|null $interest
 * @property CarbonImmutable|null $preferred_date
 * @property string|null $message
 * @property LeadStatus $status
 * @property string|null $lost_reason
 * @property CarbonImmutable|null $next_follow_up_at
 * @property LeadSource $source
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_term
 * @property string|null $utm_content
 * @property string|null $fbclid
 * @property string|null $gclid
 * @property string|null $landing_url
 * @property string|null $referrer
 * @property CarbonImmutable|null $first_touch_at
 * @property bool $consent_tracking
 * @property string|null $tracking_event_id
 * @property int|null $assigned_to
 * @property int|null $user_id
 * @property int|null $booked_order_id
 * @property CarbonImmutable|null $booked_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $assignee
 * @property-read User|null $user
 * @property-read Order|null $bookedOrder
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use AuditsAdminChanges, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'instagram_handle',
        'interest',
        'preferred_date',
        'message',
        'status',
        'source',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'fbclid',
        'gclid',
        'landing_url',
        'referrer',
        'first_touch_at',
        'consent_tracking',
        'tracking_event_id',
        'created_by',
    ];

    /**
     * Changes go through the lead Actions (which log them); deleting in
     * the panel doesn't, so it is audited here.
     */
    public function auditedEvents(): array
    {
        return ['deleted'];
    }

    public function auditLogName(): string
    {
        return 'leads';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function bookedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'booked_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Best way to reach the lead, for cards and lists. */
    public function contactLine(): string
    {
        return collect([
            $this->instagram_handle === null ? null : '@'.$this->instagram_handle,
            $this->phone,
            $this->email,
        ])->filter()->implode(' · ');
    }

    public function isFollowUpDue(): bool
    {
        return $this->status->isOpen()
            && $this->next_follow_up_at !== null
            && $this->next_follow_up_at->lte(now(config('app.display_timezone'))->startOfDay());
    }

    /**
     * @return HasMany<LeadNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interest' => LeadInterest::class,
            'status' => LeadStatus::class,
            'source' => LeadSource::class,
            'preferred_date' => 'immutable_date',
            'next_follow_up_at' => 'immutable_date',
            'first_touch_at' => 'immutable_datetime',
            'booked_at' => 'immutable_datetime',
            'consent_tracking' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
