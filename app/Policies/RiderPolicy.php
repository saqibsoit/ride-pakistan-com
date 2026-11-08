<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Rider;
use App\Models\User;

/** Admins manage every rider; customers manage their own. */
final class RiderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Rider $rider): bool
    {
        return $user->isAdmin() || $rider->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return ! $user->isStaff();
    }

    public function update(User $user, Rider $rider): bool
    {
        return $user->isAdmin() || $rider->user_id === $user->id;
    }

    /** Riders on bookings or orders stay for the history. */
    public function delete(User $user, Rider $rider): bool
    {
        return ($user->isAdmin() || $rider->user_id === $user->id)
            && ! $rider->isInUse();
    }
}
