<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BankTransfer;
use App\Models\User;

/**
 * Transfer records are read-only in the panel. Reviewing (approve, reject,
 * extend) and opening the proof file are admin-only: staff never see
 * customers' banking screenshots.
 */
final class BankTransferPolicy extends AdminReadOnlyPolicy
{
    public function review(User $user, ?BankTransfer $transfer = null): bool
    {
        return $user->isAdmin();
    }

    public function viewProof(User $user, BankTransfer $transfer): bool
    {
        return $user->isAdmin();
    }
}
