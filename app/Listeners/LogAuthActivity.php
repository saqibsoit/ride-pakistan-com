<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Auth\RecordFailedTeamLogin;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Audit log for team sign-ins and sign-outs, and failed admin-panel
 * password attempts (PLAN §7: audit every admin action). Customers sign
 * in with one-time codes and are audited by their own actions; a failed
 * attempt with an unknown email or a customer's email records nothing,
 * so the log can't be used to tell which emails have accounts.
 */
final class LogAuthActivity
{
    public function __construct(private readonly RecordFailedTeamLogin $recordFailure) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User || ! $event->user->isTeamMember()) {
            return;
        }

        activity('auth')
            ->performedOn($event->user)
            ->causedBy($event->user)
            ->event('admin_signed_in')
            ->withProperties(['ip' => request()->ip()])
            ->log('Signed in');
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User || ! $event->user->isTeamMember()) {
            return;
        }

        activity('auth')
            ->performedOn($event->user)
            ->causedBy($event->user)
            ->event('admin_signed_out')
            ->log('Signed out');
    }

    public function handleFailed(Failed $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $request = request();

        $this->recordFailure->handle($event->user, RecordFailedTeamLogin::KIND_PASSWORD, $request->ip(), $request->userAgent());
    }
}
