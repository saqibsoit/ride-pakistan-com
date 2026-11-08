<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LogInCustomer;
use App\Actions\Auth\RequestLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Actions\Referrals\AttachReferral;
use App\Exceptions\LoginCodeRejected;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ReferralLinkController;
use App\Http\Requests\Auth\RequestLoginCodeRequest;
use App\Http\Requests\Auth\VerifyLoginCodeRequest;
use App\Support\Turnstile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * /login: email → one-time code → signed in (PLAN §6). Responses never say
 * whether an account exists for the email.
 */
final class LoginController extends Controller
{
    public const PENDING_EMAIL = 'login.email';

    public const PENDING_RESTORE = 'login.restore';

    public function show(Turnstile $turnstile): View
    {
        return view('auth.login', [
            'turnstileSiteKey' => $turnstile->siteKey(),
        ]);
    }

    public function request(RequestLoginCodeRequest $request, RequestLoginCode $requestCode): RedirectResponse
    {
        $email = $request->email();

        $requestCode->handle($email, $request->ip());

        $request->session()->put(self::PENDING_EMAIL, $email);

        return redirect()->route('login.verify')->with('status', 'code-sent');
    }

    public function showVerify(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get(self::PENDING_EMAIL);

        if (! is_string($email)) {
            return redirect()->route('login');
        }

        return view('auth.verify', [
            'email' => $email,
            'length' => (int) config('login.code.length'),
            'minutes' => (int) config('login.code.ttl_minutes'),
        ]);
    }

    public function verify(VerifyLoginCodeRequest $request, VerifyLoginCode $verifyCode, LogInCustomer $logIn): RedirectResponse
    {
        $session = $request->session();
        $email = $session->get(self::PENDING_EMAIL);

        if (! is_string($email)) {
            return redirect()->route('login');
        }

        try {
            $user = $verifyCode->handle($email, $request->string('code')->toString());
        } catch (LoginCodeRejected $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        $session->forget(self::PENDING_EMAIL);

        // Deleted inside the grace period: offer to keep the account.
        if ($user->trashed()) {
            $session->regenerate();
            $session->put(self::PENDING_RESTORE, [
                'user_id' => $user->id,
                'expires_at' => now()->addMinutes(15)->getTimestamp(),
            ]);

            return redirect()->route('account.restore');
        }

        // Referral link followed in this visit (milestone 10e): only a brand-new account counts.
        $referralCode = $session->pull(ReferralLinkController::SESSION_KEY);

        if ($user->wasRecentlyCreated && is_string($referralCode)) {
            app(AttachReferral::class)->handle($referralCode, $user);
        }

        $logIn->handle($user, $session);

        return redirect()->intended(route('account.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
