<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use App\Actions\Credit\AccountCredit;
use App\Enums\BookingStatus;
use App\Enums\DiscountKind;
use App\Enums\OrderStatus;
use App\Enums\ReferralStatus;
use App\Enums\SettingKey;
use App\Models\Booking;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\ReferralRewardEarned;
use App\Support\Money;
use App\Support\Notifier;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Pays the referrer once the referred customer has attended a session
 * they paid for (milestone 10e). "Paid" means the booking's order, or the
 * order that bought its credit pack, is paid or partially refunded; manual
 * no-charge bookings and fully refunded orders don't count. The abuse
 * checks run again first (a phone number may have been added since
 * sign-up). The credit is `referrals.referrer_credit_pkr`; 0 leaves the
 * referral pending. At most one reward per referral: the referral row is
 * locked and the ledger has a unique referral_id.
 */
final class RewardReferral
{
    public function __construct(
        private readonly ReferralAbuseCheck $check,
        private readonly AccountCredit $credit,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
    ) {}

    public function handle(Booking $booking): ?Referral
    {
        $booking->loadMissing(['order', 'creditPack.order']);

        if ($booking->status !== BookingStatus::Attended || ! self::paid($booking)) {
            return null;
        }

        $amount = max(0, $this->settings->int(SettingKey::ReferralReferrerCreditPkr) ?? 0) * Money::MINOR_PER_MAJOR;

        $rewarded = DB::transaction(function () use ($booking, $amount): ?Referral {
            $referral = Referral::query()->where('referred_id', $booking->user_id)->lockForUpdate()->first();

            if ($referral === null || $referral->status !== ReferralStatus::Pending) {
                return null;
            }

            $referrer = User::withTrashed()->findOrFail($referral->referrer_id);
            $referred = User::withTrashed()->findOrFail($referral->referred_id);
            $reason = $this->check->reason($referrer, $referred);

            if ($reason !== null) {
                $referral->status = ReferralStatus::Rejected;
                $referral->rejection_reason = $reason;
                $referral->save();

                activity('referrals')->performedOn($referral)->event('rejected')->withProperties(['reason' => $reason])->log('Referral rejected at reward time: '.$reason);

                return null;
            }

            if ($amount === 0) {
                return null;
            }

            $this->credit->rewardReferral($referral, $amount);

            $referral->status = ReferralStatus::Rewarded;
            $referral->rewarded_booking_id = $booking->id;
            $referral->rewarded_at = now()->toImmutable();
            $referral->save();

            activity('referrals')
                ->performedOn($referral)
                ->event('rewarded')
                ->withProperties(['booking_id' => $booking->id, 'amount_minor' => $amount])
                ->log('Referral rewarded');

            return $referral;
        });

        if ($rewarded !== null) {
            $referrer = User::query()->find($rewarded->referrer_id);

            if ($referrer !== null) {
                $this->notifier->send($referrer, new ReferralRewardEarned($rewarded, $amount), Notifier::key(ReferralRewardEarned::type(), $rewarded, $referrer));
            }
        }

        return $rewarded;
    }

    private static function paid(Booking $booking): bool
    {
        $order = $booking->order ?? $booking->creditPack?->order;

        if ($order === null || ! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
            return false;
        }

        // Money paid: by card or transfer, or with a gift voucher or account
        // credit (milestone 10e Part B). Promo and referral discounts alone don't count.
        return $order->amount_minor > 0
            || $order->discounts()->whereIn('kind', [DiscountKind::Voucher, DiscountKind::AccountCredit])->exists();
    }
}
