<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Rider;
use App\Models\User;

/** Admins manage every rider; customers manage their own; the team and a rider's trainers see the session history. */
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

    /**
     * A rider's session history with horses and notes (milestone 10b): the
     * office team, or a trainer who has (or had) the rider in one of their
     * sessions. Trainers see name, level, notes and emergency contact only.
     */
    public function viewHistory(User $user, Rider $rider): bool
    {
        if ($user->isTeamMember()) {
            return true;
        }

        return $user->isTrainer()
            && $rider->bookings()->whereHas('slot', fn ($slot) => $slot->where('trainer_id', $user->id))->exists();
    }

    public function create(User $user): bool
    {
        return ! $user->isPanelUser();
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
