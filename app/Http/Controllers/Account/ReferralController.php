<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Credit\AccountCredit;
use App\Actions\Referrals\EnsureReferralCode;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /account/referrals (milestone 10e): the customer's personal link, the
 * friends who signed up with it (first names only) and their PKR account
 * credit with its history.
 */
final class ReferralController extends Controller
{
    public function __invoke(Request $request, AccountCredit $credit, Settings $settings): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.referrals', [
            'link' => EnsureReferralCode::url($user),
            'referrals' => $user->referrals()->with('referred')->latest('id')->get(),
            'balanceMinor' => $credit->balance($user),
            'entries' => $user->accountCredit()->latest('id')->limit(50)->get(),
            'friendDiscountMinor' => max(0, $settings->int(SettingKey::ReferralFriendDiscountPkr) ?? 0) * Money::MINOR_PER_MAJOR,
            'rewardMinor' => max(0, $settings->int(SettingKey::ReferralReferrerCreditPkr) ?? 0) * Money::MINOR_PER_MAJOR,
        ]);
    }
}
