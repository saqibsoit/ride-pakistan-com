<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Sign out everywhere": bumps the session version (EnsureSessionIsCurrent
 * ends every session carrying the old one, this one included) and rotates
 * the remember token. Logged.
 */
final class LogOutEverywhere
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->increment('session_version', 1, ['remember_token' => Str::random(60)]);

            activity('account')
                ->performedOn($user)
                ->causedBy($user)
                ->event('signed-out-everywhere')
                ->log('Signed out on every device');
        });
    }
}
