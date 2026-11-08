<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Filament's login (5 attempts a minute per IP) plus a per-account limit,
 * so a distributed guess at one email address is throttled too (PLAN §7).
 */
class Login extends BaseLogin
{
    public const MAX_ATTEMPTS_PER_EMAIL = 5;

    public const DECAY_SECONDS = 900;

    public function authenticate(): ?LoginResponse
    {
        $key = $this->emailThrottleKey();

        if ($key !== null && RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_EMAIL)) {
            Notification::make()
                ->danger()
                ->title('Too many sign-in attempts')
                ->body('Please try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.')
                ->send();

            return null;
        }

        if ($key !== null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }

        $response = parent::authenticate();

        // Only a completed sign-in (after any MFA challenge) clears the counter.
        if ($response !== null && $key !== null) {
            RateLimiter::clear($key);
        }

        return $response;
    }

    private function emailThrottleKey(): ?string
    {
        $email = $this->data['email'] ?? null;

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return 'admin-login:'.hash('sha256', Str::lower(trim($email)));
    }
}
