<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Horse;
use App\Models\User;

/**
 * Horses (milestone 10b): admins manage them. Staff and trainers only pick
 * from the working horses when assigning (assign); the panel list is
 * admin-only. A horse that has carried riders is retired, never deleted.
 */
final class HorsePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Horse $horse): bool
    {
        return $user->isAdmin();
    }

    /** Pick from the working horses when assigning. */
    public function assign(User $user): bool
    {
        return $user->isTeamMember() || $user->isTrainer();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Horse $horse): bool
    {
        return $user->isAdmin() && ! $horse->isRetired();
    }

    public function retire(User $user, Horse $horse): bool
    {
        return $user->isAdmin() && ! $horse->isRetired();
    }

    public function restore(User $user, Horse $horse): bool
    {
        return $user->isAdmin() && $horse->isRetired();
    }

    public function delete(User $user, Horse $horse): bool
    {
        return $user->isAdmin() && ! $horse->bookings()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
