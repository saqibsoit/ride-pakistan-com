<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CreditPackStatus;
use App\Models\CreditPack;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only records for admins (credits change only through the booking
 * actions); customers see their own. Admins may extend a pack's expiry.
 */
final class CreditPackPolicy extends AdminReadOnlyPolicy
{
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model)
            || ($model instanceof CreditPack && $model->user_id === $user->id);
    }

    public function extend(User $user, CreditPack $pack): bool
    {
        return $user->isAdmin() && $pack->status !== CreditPackStatus::Revoked;
    }
}
