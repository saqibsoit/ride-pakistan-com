<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use App\Enums\ReferralStatus;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records that a new customer signed up through a referral link
 * (milestone 10e). Called once, when the visit that followed /r/{code}
 * creates the account. A failed abuse check is recorded as rejected with
 * its reason (never silently dropped), so admins can see it.
 */
final class AttachReferral
{
    public function __construct(private readonly ReferralAbuseCheck $check) {}

    public function handle(string $code, User $referred): ?Referral
    {
        $referrer = User::withTrashed()->where('referral_code', $code)->first();

        if ($referrer === null || $referred->referredBy()->exists()) {
            return null;
        }

        try {
            $referral = Referral::query()->create([
                'referrer_id' => $referrer->id,
                'referred_id' => $referred->id,
                'signup_email' => $referred->email,
                'signup_phone' => $referred->phone,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // attached by a parallel request
        }

        $reason = $this->check->reason($referrer, $referred);

        if ($reason !== null) {
            $referral->status = ReferralStatus::Rejected;
            $referral->rejection_reason = $reason;
            $referral->save();
        }

        activity('referrals')
            ->performedOn($referral)
            ->causedBy($referred)
            ->event($reason === null ? 'attached' : 'rejected')
            ->withProperties(['referrer_id' => $referrer->id, 'reason' => $reason])
            ->log($reason === null ? 'Referral recorded' : 'Referral rejected: '.$reason);

        return $referral;
    }
}
