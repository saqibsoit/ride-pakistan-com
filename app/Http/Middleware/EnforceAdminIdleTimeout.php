<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel idle timeout (PLAN §7, milestone 10). A panel session with
 * no real activity for security.admin.idle_minutes [30] is signed out.
 * Livewire polling (dashboard widgets, tables) carries no calls and no
 * updates, so it does not count as activity and can't keep an unattended
 * screen signed in.
 */
final class EnforceAdminIdleTimeout
{
    public const SESSION_KEY = 'admin.last_activity_at';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();
        $last = $session->get(self::SESSION_KEY);
        $limit = max(1, (int) config('security.admin.idle_minutes', 30)) * 60;

        if (is_int($last) && $now - $last > $limit) {
            activity('auth')
                ->causedBy($request->user())
                ->event('admin_idle_timeout')
                ->log('Signed out of the admin panel after inactivity');

            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();
            $session->flash('admin.idle_timeout', true);

            $login = Filament::getLoginUrl() ?? '/';

            if ($request->hasHeader('X-Livewire')) {
                return response()->json(['message' => 'Your session has ended. Please sign in again.'], 419);
            }

            return redirect()->to($login);
        }

        if (! is_int($last) || self::isActivity($request)) {
            $session->put(self::SESSION_KEY, $now);
        }

        return $next($request);
    }

    /** A page load, or a Livewire request that changes or calls something. */
    private static function isActivity(Request $request): bool
    {
        if (! $request->hasHeader('X-Livewire')) {
            return true;
        }

        $components = $request->input('components');

        if (! is_array($components)) {
            return true;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            if (! empty($component['updates'])) {
                return true;
            }

            foreach ((array) ($component['calls'] ?? []) as $call) {
                if (is_array($call) && ($call['method'] ?? null) !== '$refresh') {
                    return true;
                }
            }
        }

        return false;
    }
}
