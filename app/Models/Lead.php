<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadInterest;
use App\Enums\LeadStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales lead. Milestone 2 creates leads from the contact form; notes
 * exist from milestone 3; the CRM (pipeline, assignment) arrives in milestone 9.
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property LeadInterest|null $interest
 * @property CarbonImmutable|null $preferred_date
 * @property string|null $message
 * @property LeadStatus $status
 * @property string $source
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_term
 * @property string|null $utm_content
 * @property string|null $fbclid
 * @property string|null $gclid
 * @property string|null $landing_url
 * @property string|null $referrer
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'phone',
        'email',
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
    ];

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
            'preferred_date' => 'immutable_date',
        ];
    }
}
