<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\LogOutEverywhere;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Ends every session for the account, this one included. */
final class SignOutEverywhereController extends Controller
{
    public function __invoke(Request $request, LogOutEverywhere $logOutEverywhere): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $logOutEverywhere->handle($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'signed-out-everywhere');
    }
}
