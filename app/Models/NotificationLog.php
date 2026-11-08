<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One notification queued for a user (milestone 8). The dedupe key makes
 * automatic sends happen once; resends record the admin who sent them.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $type
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $dedupe_key
 * @property int|null $resent_by
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable|null $sent_at
 * @property-read User|null $user
 */
class NotificationLog extends Model
{
    protected $table = 'notification_log';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'subject_type',
        'subject_id',
        'dedupe_key',
        'resent_by',
        'queued_at',
        'sent_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
