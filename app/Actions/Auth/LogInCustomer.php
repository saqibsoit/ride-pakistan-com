<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Signs a verified customer in: new session id (no fixation), new CSRF
 * token, and the account's current session version (PLAN §7).
 */
final class LogInCustomer
{
    public function handle(User $user, Session $session): void
    {
        Auth::guard('web')->login($user);

        $session->regenerate();
        $session->regenerateToken();
        $session->put(EnsureSessionIsCurrent::SESSION_KEY, $user->session_version);
    }
}
