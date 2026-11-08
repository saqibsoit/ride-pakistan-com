<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Sign out everywhere" (PLAN §7). Each signed-in session remembers the
 * account's session_version; bumping the version (LogOutEverywhere,
 * account deletion) ends every session that carries an older one. Works
 * with any session driver and for accounts without a password.
 */
final class EnsureSessionIsCurrent
{
    public const SESSION_KEY = 'auth.session_version';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $version = $session->get(self::SESSION_KEY);

        // Sessions started elsewhere (the admin login) pick up the version
        // on their first request.
        if ($version === null) {
            $session->put(self::SESSION_KEY, $user->session_version);

            return $next($request);
        }

        if ((int) $version === $user->session_version) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Your session has ended. Please sign in again.'], 401);
        }

        $panelLogin = $request->routeIs('filament.*') ? Filament::getLoginUrl() : null;

        return $panelLogin !== null
            ? redirect()->to($panelLogin)
            : redirect()->route('login')->with('status', 'signed-out-everywhere');
    }
}
