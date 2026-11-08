<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\VoucherStatus;
use App\Models\User;
use App\Models\Voucher;

/**
 * Gift vouchers (milestone 10e): admin-only. Records are never edited by
 * hand; balances change through VoucherBalance, new vouchers through
 * IssueVoucher or IssueManualVoucher.
 */
final class VoucherPolicy extends AdminReadOnlyPolicy
{
    public function issue(User $user): bool
    {
        return $user->isAdmin();
    }

    public function manage(User $user, Voucher $voucher): bool
    {
        return $user->isAdmin() && in_array($voucher->status, [VoucherStatus::Active, VoucherStatus::Expired], true);
    }
}
