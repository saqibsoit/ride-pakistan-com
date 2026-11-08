<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VoucherOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Gift voucher purchases (milestone 10e): read-only for admins in the
 * panel (changes go through Actions); customers see and pay their own.
 * Reviewing transfers and opening proofs is admin-only, as for orders.
 */
final class VoucherOrderPolicy extends AdminReadOnlyPolicy
{
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model)
            || ($model instanceof VoucherOrder && $model->user_id === $user->id);
    }

    public function pay(User $user, VoucherOrder $order): bool
    {
        return $order->user_id === $user->id;
    }

    public function review(User $user, ?VoucherOrder $order = null): bool
    {
        return $user->isAdmin();
    }

    public function viewProof(User $user, VoucherOrder $order): bool
    {
        return $user->isAdmin();
    }
}
