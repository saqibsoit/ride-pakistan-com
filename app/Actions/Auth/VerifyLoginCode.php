<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Auth\LoginCodes\LoginCodeChannel;
use App\Auth\LoginCodes\LoginCodeHasher;
use App\Enums\LoginCodeFailure;
use App\Exceptions\LoginCodeRejected;
use App\Models\LoginCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Checks a one-time code and returns the account it unlocks, creating a
 * customer account on the first successful sign-in. Codes are single use,
 * expire after config('login.code.ttl_minutes') and die after
 * config('login.code.max_attempts') wrong guesses. The code row is locked
 * so two parallel submissions can't both use it.
 *
 * The returned user may be soft-deleted (inside the deletion grace
 * period): the caller offers to restore it instead of signing in.
 */
final class VerifyLoginCode
{
    public function __construct(
        private readonly LoginCodeChannel $channel,
        private readonly LoginCodeHasher $hasher,
    ) {}

    /**
     * @throws LoginCodeRejected
     */
    public function handle(string $destination, string $code): User
    {
        $destination = $this->channel->normalise($destination);
        $code = preg_replace('/\D/', '', $code) ?? '';

        // The transaction returns the failure instead of throwing, so a
        // wrong guess (attempts + 1) is committed, not rolled back.
        $result = DB::transaction(function () use ($destination, $code): User|LoginCodeRejected {
            $now = CarbonImmutable::now();

            $loginCode = LoginCode::query()
                ->where('channel', $this->channel->type())
                ->where('destination', $destination)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($loginCode === null || $loginCode->isExpired($now)) {
                return new LoginCodeRejected(LoginCodeFailure::Expired);
            }

            if ($loginCode->attemptsExhausted()) {
                return new LoginCodeRejected(LoginCodeFailure::Locked);
            }

            if (! $this->hasher->matches($loginCode->code_hash, $destination, $code)) {
                $loginCode->increment('attempts');

                if ($loginCode->attemptsExhausted()) {
                    $loginCode->forceFill(['consumed_at' => $now])->save();

                    return new LoginCodeRejected(LoginCodeFailure::Locked);
                }

                $left = (int) config('login.code.max_attempts') - $loginCode->attempts;

                return new LoginCodeRejected(LoginCodeFailure::Invalid, $left);
            }

            $loginCode->forceFill(['consumed_at' => $now])->save();

            return $this->accountFor($destination, $now);
        });

        if ($result instanceof LoginCodeRejected) {
            throw $result;
        }

        return $result;
    }

    private function accountFor(string $email, CarbonImmutable $now): User|LoginCodeRejected
    {
        $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();

        // Defence in depth: RequestLoginCode never sends these a code.
        if ($user !== null && ($user->isTeamMember() || $user->isAnonymised())) {
            return new LoginCodeRejected(LoginCodeFailure::Expired);
        }

        if ($user === null) {
            $user = new User(['email' => $email]);
            $user->forceFill(['email_verified_at' => $now])->save();

            return $user;
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => $now])->save();
        }

        return $user;
    }
}
