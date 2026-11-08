<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Actions\Auth\RecordFailedTeamLogin;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Filament's login (5 attempts a minute per IP) plus a per-account limit,
 * so a distributed guess at one email address is throttled too (PLAN §7).
 * Wrong MFA codes count as failed sign-ins for the alert email (Filament
 * fires the `Failed` event only for wrong passwords).
 */
class Login extends BaseLogin
{
    public const MAX_ATTEMPTS_PER_EMAIL = 5;

    public const DECAY_SECONDS = 900;

    public function mount(): void
    {
        parent::mount();

        if (session()->pull('admin.idle_timeout') === true) {
            Notification::make()
                ->warning()
                ->title('You were signed out')
                ->body('Your admin session ended after '.(int) config('security.admin.idle_minutes', 30).' minutes without activity.')
                ->send();
        }
    }

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

        $challengedUser = $this->userInMultiFactorChallenge();

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            // Password already accepted, so this was the MFA code.
            if ($challengedUser !== null && $this->hasMultiFactorError($exception)) {
                app(RecordFailedTeamLogin::class)->handle($challengedUser, RecordFailedTeamLogin::KIND_MFA, request()->ip(), request()->userAgent());
            }

            throw $exception;
        }

        // Only a completed sign-in (after any MFA challenge) clears the counter.
        if ($response !== null && $key !== null) {
            RateLimiter::clear($key);
        }

        return $response;
    }

    private function userInMultiFactorChallenge(): ?User
    {
        if (blank($this->userUndertakingMultiFactorAuthentication)) {
            return null;
        }

        try {
            $data = decrypt($this->userUndertakingMultiFactorAuthentication);
        } catch (Throwable) {
            return null;
        }

        $id = is_array($data) ? ($data['identifier'] ?? null) : null;

        return is_int($id) || is_string($id) ? User::query()->find($id) : null;
    }

    private function hasMultiFactorError(ValidationException $exception): bool
    {
        // The challenge form's state lives under data.multiFactor.
        foreach (array_keys($exception->errors()) as $field) {
            if (str_starts_with($field, 'data.multiFactor')) {
                return true;
            }
        }

        return false;
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
