<?php

declare(strict_types=1);

namespace App\Actions\Discounts;

use App\Actions\Referrals\ReferralAbuseCheck;
use App\Enums\ReferralStatus;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;

/**
 * The friend's discount (milestone 10e Part B): a customer who signed up
 * through a referral link gets referrals.friend_discount_pkr [1,000] off
 * their first paid booking, never more than the order. Only while the
 * referral is pending and still passes the abuse checks; 0 turns it off.
 * It counts as the order's promo: a promo code replaces it.
 */
final class ReferralDiscount
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ReferralAbuseCheck $abuse,
    ) {}

    public function referralFor(User $customer, Order $order): ?Referral
    {
        $pkr = $this->settings->int(SettingKey::ReferralFriendDiscountPkr) ?? 0;

        if ($pkr <= 0) {
            return null;
        }

        $referral = Referral::query()->where('referred_id', $customer->id)->with('referrer')->first();

        if ($referral === null || $referral->status !== ReferralStatus::Pending || PromoEligibility::hasPaidBefore($customer, $order)) {
            return null;
        }

        return $this->abuse->reason($referral->referrer, $customer) === null ? $referral : null;
    }

    public function amountFor(int $subtotalMinor): int
    {
        $pkr = $this->settings->int(SettingKey::ReferralFriendDiscountPkr) ?? 0;

        return max(0, min($pkr * Money::MINOR_PER_MAJOR, $subtotalMinor));
    }
}
