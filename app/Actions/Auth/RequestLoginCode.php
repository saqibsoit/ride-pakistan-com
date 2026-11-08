<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Auth\LoginCodes\LoginCodeChannel;
use App\Auth\LoginCodes\LoginCodeHasher;
use App\Enums\SettingKey;
use App\Models\LoginCode;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Issues a one-time sign-in code (PLAN §6, §7). The caller always shows
 * the same neutral response, so nothing here reveals whether an account
 * exists. No code is sent to:
 *  - staff and admins: a code would skip the admin panel's MFA challenge;
 *  - accounts deleted longer ago than the grace period (kept, locked,
 *    until anonymised).
 * A new code replaces any earlier unused code for the same destination.
 */
final class RequestLoginCode
{
    public function __construct(
        private readonly LoginCodeChannel $channel,
        private readonly LoginCodeHasher $hasher,
        private readonly Settings $settings,
    ) {}

    /** @return bool whether a code was sent */
    public function handle(string $destination, ?string $ipAddress = null): bool
    {
        $destination = $this->channel->normalise($destination);

        if (! $this->mayReceiveCode($destination)) {
            return false;
        }

        $code = $this->generateCode();
        $now = CarbonImmutable::now();
        $expiresAt = $now->addMinutes((int) config('login.code.ttl_minutes'));

        DB::transaction(function () use ($destination, $code, $now, $expiresAt, $ipAddress): void {
            LoginCode::query()
                ->where('channel', $this->channel->type())
                ->where('destination', $destination)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);

            LoginCode::query()->create([
                'channel' => $this->channel->type(),
                'destination' => $destination,
                'code_hash' => $this->hasher->hash($destination, $code),
                'expires_at' => $expiresAt,
                'ip_address' => $ipAddress,
            ]);
        });

        $this->channel->send($destination, $code, $expiresAt);

        return true;
    }

    private function mayReceiveCode(string $email): bool
    {
        $user = User::withTrashed()->where('email', $email)->first();

        if ($user === null) {
            return true;
        }

        if ($user->isPanelUser() || $user->isAnonymised()) {
            return false;
        }

        if ($user->trashed()) {
            return $user->withinDeletionGracePeriod($this->settings->int(SettingKey::DeletionGraceDays) ?? 30);
        }

        return true;
    }

    private function generateCode(): string
    {
        $length = (int) config('login.code.length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
