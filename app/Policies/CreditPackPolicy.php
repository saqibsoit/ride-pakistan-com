<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CreditPack;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Read-only for admins (extensions arrive in milestone 5); customers see their own. */
final class CreditPackPolicy extends AdminReadOnlyPolicy
{
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model)
            || ($model instanceof CreditPack && $model->user_id === $user->id);
    }
}
