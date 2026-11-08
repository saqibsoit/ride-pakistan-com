<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Customer and team accounts. Accounts are created by code sign-in or
 * admin:create, never from the panel.
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

    /** Admin panel: accounts are deleted by their owner (deleteAccount). */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    /** "Download my data": only your own. */
    public function exportData(User $user, User $model): bool
    {
        return $user->is($model);
    }

    /** "Delete my account": customers only; team accounts are removed by an admin. */
    public function deleteAccount(User $user, User $model): bool
    {
        return $user->is($model) && ! $user->isTeamMember();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
