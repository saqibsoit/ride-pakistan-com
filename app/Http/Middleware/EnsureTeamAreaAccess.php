<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /team (run sheets and rider history, milestone 10b) is for staff, admins
 * and trainers. Like /checkin: guests sign in at the admin panel (MFA) and
 * come back here; customers get 403; accounts without an authenticator app
 * finish setting it up first. Policies then decide per slot and rider.
 */
final class EnsureTeamAreaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $panel = Filament::getPanel('admin');

        if (! $user instanceof User) {
            redirect()->setIntendedUrl($request->fullUrl());

            return redirect()->to($panel?->getLoginUrl() ?? '/');
        }

        abort_unless($user->can('team-area'), 403);

        if (blank($user->app_authentication_secret)) {
            return redirect()->to($panel?->getUrl() ?? '/');
        }

        return $next($request);
    }
}
