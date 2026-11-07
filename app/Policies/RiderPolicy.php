<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Rider;
use App\Models\User;

/** Admins manage every rider; customers manage their own (milestone 4). */
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

    /** Riders with bookings stay for the booking history. */
    public function delete(User $user, Rider $rider): bool
    {
        return ($user->isAdmin() || $rider->user_id === $user->id)
            && ! $rider->bookings()->exists();
    }
}
