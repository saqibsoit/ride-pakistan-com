<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Read-only for admins (actions arrive with payments); customers see their own. */
final class OrderPolicy extends AdminReadOnlyPolicy
{
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model)
            || ($model instanceof Order && $model->user_id === $user->id);
    }

    /** Only the customer who placed the order pays for it. */
    public function pay(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }

    /** Admins refund paid card orders (RefundOrder checks the rest). */
    public function refund(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }
}
