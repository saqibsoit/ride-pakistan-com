<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginCodeChannelType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A one-time sign-in code. The code itself is never stored, only an
 * HMAC-SHA256 of it keyed with the app key (App\Actions\Auth\LoginCodeHasher).
 *
 * @property int $id
 * @property LoginCodeChannelType $channel
 * @property string $destination
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property string|null $ip_address
 * @property CarbonImmutable $created_at
 */
class LoginCode extends Model
{
    use Prunable;

    /** @var list<string> */
    protected $fillable = [
        'channel',
        'destination',
        'code_hash',
        'expires_at',
        'ip_address',
    ];

    /** @var list<string> */
    protected $hidden = [
        'code_hash',
    ];

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return ! $this->expires_at->isAfter($now ?? CarbonImmutable::now());
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function attemptsExhausted(): bool
    {
        return $this->attempts >= (int) config('login.code.max_attempts');
    }

    /**
     * Codes are useless a day after they expire.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => LoginCodeChannelType::class,
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
