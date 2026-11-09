<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Auth\ChangeTemporaryPassword;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel: while a team member still has the temporary password from
 * admin:create, every panel page and Livewire request is blocked except the
 * change-password screen and signing out. Runs before Filament's required
 * MFA check, so the order is: new password, then authenticator app.
 * Persistent, so Livewire requests from a page opened earlier are blocked too.
 */
final class EnsurePasswordIsChanged
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(ChangeTemporaryPassword::ROUTE_NAME, 'filament.admin.auth.logout')) {
            return $next($request);
        }

        if ($request->hasHeader('X-Livewire')) {
            return response()->json(['message' => 'Change your temporary password first.'], 403);
        }

        return redirect()->route(ChangeTemporaryPassword::ROUTE_NAME);
    }
}
