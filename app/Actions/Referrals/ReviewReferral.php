<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\ReferralStatus;
use App\Exceptions\CreditRejected;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin overrides for a referral (milestone 10e). Reject a pending one
 * (reason required: e.g. the same person on two accounts) or reinstate a
 * rejected one as pending (reason required: the automatic check was
 * wrong). A rewarded referral is final; take the credit back with an
 * account credit adjustment if needed.
 */
final class ReviewReferral
{
    /** @throws CreditRejected */
    public function reject(Referral $referral, User $admin, string $reason): Referral
    {
        return $this->move($referral, $admin, $reason, ReferralStatus::Pending, ReferralStatus::Rejected);
    }

    /** @throws CreditRejected */
    public function reinstate(Referral $referral, User $admin, string $reason): Referral
    {
        return $this->move($referral, $admin, $reason, ReferralStatus::Rejected, ReferralStatus::Pending);
    }

    /** @throws CreditRejected */
    private function move(Referral $referral, User $admin, string $reason, ReferralStatus $from, ReferralStatus $to): Referral
    {
        $reason = OrderStateMachine::cleanReason($reason);

        if (! $admin->isAdmin()) {
            throw new CreditRejected('Only admins can review referrals.');
        }

        if ($reason === null) {
            throw new CreditRejected('Give a reason.');
        }

        return DB::transaction(function () use ($referral, $admin, $reason, $from, $to): Referral {
            $locked = Referral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $from) {
                throw new CreditRejected('This referral is '.$locked->status->value.' and can\'t be changed that way.');
            }

            $locked->status = $to;
            $locked->rejection_reason = $to === ReferralStatus::Rejected ? $reason : null;
            $locked->save();

            activity('referrals')
                ->performedOn($locked)
                ->causedBy($admin)
                ->event($to === ReferralStatus::Rejected ? 'rejected' : 'reinstated')
                ->withProperties(['reason' => $reason])
                ->log('Referral '.($to === ReferralStatus::Rejected ? 'rejected' : 'reinstated').' by an admin');

            return $referral->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
