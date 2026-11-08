<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Promo codes (milestone 10e): admins create and edit them (through
 * SavePromoCode). Removing goes through ArchivePromoCode: unused codes are
 * deleted, used ones archived; never a plain delete.
 */
final class PromoCodePolicy extends AdminReadOnlyPolicy
{
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function archive(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }
}
