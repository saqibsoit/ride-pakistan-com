<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Customer and team accounts in the admin. Accounts are created by sign-up
 * (milestone 4) or admin:create, never from the panel.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /** Admins cannot change their own role (no accidental lock-out). */
    public function changeRole(User $user, User $model): bool
    {
        return $user->isAdmin() && ! $user->is($model);
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
