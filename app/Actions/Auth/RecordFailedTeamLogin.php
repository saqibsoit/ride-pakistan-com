<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\FailedTeamLogin;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A failed admin-panel sign-in (wrong password or wrong MFA code) for a
 * team account (PLAN §7, milestone 10). Every failure is audited; once
 * security.admin.failed_login_alert_threshold [5] failures land within
 * the window, the account itself and every admin get an email, at most
 * once an hour per account. Customer accounts and unknown emails never
 * trigger anything, so the alert can't reveal whether an account exists.
 */
final class RecordFailedTeamLogin
{
    public const KIND_PASSWORD = 'password';

    public const KIND_MFA = 'mfa';

    public function __construct(private readonly Notifier $notifier) {}

    /** @return bool whether the alert was sent */
    public function handle(User $user, string $kind, ?string $ip, ?string $userAgent, ?CarbonImmutable $now = null): bool
    {
        if (! $user->isTeamMember()) {
            return false;
        }

        $now ??= CarbonImmutable::now();
        $window = max(1, (int) config('security.admin.failed_login_window_minutes', 15));
        $threshold = max(1, (int) config('security.admin.failed_login_alert_threshold', 5));
        $userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, 255);

        activity('auth')
            ->performedOn($user)
            ->event('admin_login_failed')
            ->withProperties(['kind' => $kind, 'ip' => $ip, 'user_agent' => $userAgent])
            ->log($kind === self::KIND_MFA ? 'Admin sign-in failed (wrong MFA code)' : 'Admin sign-in failed (wrong password)');

        $attempts = RateLimiter::hit('team-login-failures:'.$user->id, $window * 60);

        if ($attempts < $threshold) {
            return false;
        }

        $hour = $now->format('YmdH');
        $notification = fn (): FailedTeamLogin => new FailedTeamLogin($user, $attempts, $window, $ip, $userAgent, $now);

        $recipients = User::query()
            ->where('role', UserRole::Admin)
            ->whereNotNull('email')
            ->whereKeyNot($user->id)
            ->get()
            ->prepend($user);

        $sent = false;

        foreach ($recipients as $recipient) {
            $sent = $this->notifier->send(
                $recipient,
                $notification(),
                FailedTeamLogin::type().':User:'.$user->id.':'.$recipient->id.':'.$hour,
            ) || $sent;
        }

        return $sent;
    }
}
