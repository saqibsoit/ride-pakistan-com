<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Account\RestoreAccount;
use App\Actions\Auth\LogInCustomer;
use App\Enums\SettingKey;
use App\Exceptions\AccountActionBlocked;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Shown after a verified code for an account that is scheduled for
 * deletion. The verified state lives in the session for 15 minutes; the
 * customer is not signed in until they choose to keep the account.
 */
final class RestoreAccountController extends Controller
{
    public function show(Request $request, Settings $settings): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return view('auth.restore', [
            'email' => $user->email,
            'endsAt' => $user->deletionGraceEndsAt($settings->int(SettingKey::DeletionGraceDays) ?? 30),
        ]);
    }

    public function store(Request $request, RestoreAccount $restore, LogInCustomer $logIn): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        try {
            $restore->handle($user);
        } catch (AccountActionBlocked) {
            $request->session()->forget(LoginController::PENDING_RESTORE);

            return redirect()->route('login')->withErrors(['email' => 'This account can no longer be restored.']);
        }

        $request->session()->forget(LoginController::PENDING_RESTORE);
        $logIn->handle($user->refresh(), $request->session());

        return redirect()->route('account.dashboard')->with('status', 'account-restored');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(LoginController::PENDING_RESTORE);

        if (! is_array($pending) || ! is_int($pending['user_id'] ?? null) || ! is_int($pending['expires_at'] ?? null)) {
            return null;
        }

        if ($pending['expires_at'] < now()->getTimestamp()) {
            $request->session()->forget(LoginController::PENDING_RESTORE);

            return null;
        }

        return User::onlyTrashed()->find($pending['user_id']);
    }
}
