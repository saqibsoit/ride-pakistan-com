<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /r/{code} (milestone 10e): a customer's referral link. For a visitor
 * who isn't signed in, the code is kept in the session (no cookie, so no
 * consent change, like the DM lead reference) and attached if this visit
 * creates a new account. Unknown codes are ignored. Always redirects home.
 */
final class ReferralLinkController extends Controller
{
    public const SESSION_KEY = 'referral_code';

    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $code = strtoupper($code);

        if ($request->user() === null && User::query()->where('referral_code', $code)->exists()) {
            $request->session()->put(self::SESSION_KEY, $code);
        }

        return redirect()->route('home');
    }
}
