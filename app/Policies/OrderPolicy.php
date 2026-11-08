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
}
