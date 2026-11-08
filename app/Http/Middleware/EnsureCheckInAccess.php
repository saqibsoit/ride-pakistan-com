<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /checkin is for the team only (PLAN §6). Staff never get customer sign-in
 * codes, so guests go to the admin panel login (with MFA) and come back
 * here afterwards. A signed-in customer gets 403. Team accounts that have
 * not set up their authenticator app yet are sent to finish that first.
 */
final class EnsureCheckInAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $panel = Filament::getPanel('admin');

        if (! $user instanceof User) {
            redirect()->setIntendedUrl($request->fullUrl());

            return redirect()->to($panel?->getLoginUrl() ?? '/');
        }

        abort_unless($user->can('check-in'), 403);

        if (blank($user->app_authentication_secret)) {
            return redirect()->to($panel?->getUrl() ?? '/');
        }

        return $next($request);
    }
}
