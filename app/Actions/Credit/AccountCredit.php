<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\AccountCreditReason;
use App\Exceptions\CreditRejected;
use App\Models\AccountCreditEntry;
use App\Models\Order;
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
     * Spends up to $maxMinor of the customer's credit on an order at
     * checkout (milestone 10e Part B) and returns what was spent. Locks the
     * user row, so the balance never goes below zero.
     */
    public function spend(User $customer, Order $order, int $maxMinor): int
    {
        return DB::transaction(function () use ($customer, $order, $maxMinor): int {
            User::withTrashed()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $spent = min(max(0, $maxMinor), $this->balance($customer));

            if ($spent > 0) {
                AccountCreditEntry::query()->create([
                    'user_id' => $customer->id,
                    'delta_minor' => -$spent,
                    'reason' => AccountCreditReason::Used,
                    'order_id' => $order->id,
                    'note' => 'Order '.$order->reference,
                ]);
            }

            return $spent;
        });
    }

    /**
     * Puts credit back for an order: an unpaid order ended, the customer
     * stopped using credit, or a refund (milestone 10e Part B). With
     * VoucherReturned: a gift voucher share that its cancelled or expired
     * voucher could no longer take back.
     */
    public function giveBack(User $customer, Order $order, int $amountMinor, ?User $actor, string $note, AccountCreditReason $reason = AccountCreditReason::Returned): void
    {
        if ($amountMinor <= 0) {
            return;
        }

        DB::transaction(function () use ($customer, $order, $amountMinor, $actor, $note, $reason): void {
            User::withTrashed()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            AccountCreditEntry::query()->create([
                'user_id' => $customer->id,
                'delta_minor' => $amountMinor,
                'reason' => $reason,
                'order_id' => $order->id,
                'note' => mb_substr($note.' (order '.$order->reference.')', 0, 500),
                'actor_id' => $actor?->id,
            ]);
        });
    }

    /** Credit an order holds now: spent at checkout minus returned for it. */
    public static function heldBy(Order $order): int
    {
        return -1 * (int) AccountCreditEntry::query()
            ->where('order_id', $order->id)
            ->whereIn('reason', [AccountCreditReason::Used, AccountCreditReason::Returned])
            ->sum('delta_minor');
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
