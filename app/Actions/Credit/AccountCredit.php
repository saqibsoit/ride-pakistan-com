<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\AccountCreditReason;
use App\Exceptions\CreditRejected;
use App\Models\AccountCreditEntry;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A customer's PKR account credit (milestone 10e): a ledger of signed
 * entries whose sum is the balance. Earned through referrals or given by
 * an admin; spent at checkout from Part B. Every write locks the user row
 * so concurrent changes can't take the balance below zero.
 */
final class AccountCredit
{
    public function balance(User $user): int
    {
        return (int) AccountCreditEntry::query()->where('user_id', $user->id)->sum('delta_minor');
    }

    /** The referrer's reward; at most one entry per referral (unique index). */
    public function rewardReferral(Referral $referral, int $amountMinor): AccountCreditEntry
    {
        return AccountCreditEntry::query()->create([
            'user_id' => $referral->referrer_id,
            'delta_minor' => $amountMinor,
            'reason' => AccountCreditReason::ReferralReward,
            'referral_id' => $referral->id,
            'note' => 'Referral reward',
        ]);
    }

    /**
     * An admin adds or removes credit (reason required). Removing more than
     * the balance is refused.
     *
     * @throws CreditRejected
     */
    public function adjust(User $customer, User $admin, int $deltaMinor, string $reason): AccountCreditEntry
    {
        $reason = OrderStateMachine::cleanReason($reason);

        if (! $admin->isAdmin()) {
            throw new CreditRejected('Only admins can change account credit.');
        }

        if ($reason === null || $deltaMinor === 0) {
            throw new CreditRejected('Enter an amount other than zero and a reason.');
        }

        return DB::transaction(function () use ($customer, $admin, $deltaMinor, $reason): AccountCreditEntry {
            User::withTrashed()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($this->balance($customer) + $deltaMinor < 0) {
                throw new CreditRejected('That would take the credit below zero.');
            }

            $entry = AccountCreditEntry::query()->create([
                'user_id' => $customer->id,
                'delta_minor' => $deltaMinor,
                'reason' => AccountCreditReason::Adjusted,
                'note' => $reason,
                'actor_id' => $admin->id,
            ]);

            activity('referrals')
                ->performedOn($customer)
                ->causedBy($admin)
                ->event('credit_adjusted')
                ->withProperties(['delta_minor' => $deltaMinor, 'reason' => $reason])
                ->log('Account credit adjusted');

            return $entry;
        });
    }
}
