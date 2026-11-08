<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** Referrals (milestone 10e): admin-only, changed only through ReviewReferral and RewardReferral. */
final class ReferralPolicy extends AdminReadOnlyPolicy
{
    public function review(User $user): bool
    {
        return $user->isAdmin();
    }
}
