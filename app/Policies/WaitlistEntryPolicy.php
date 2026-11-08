<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WaitlistEntry;

/**
 * Waitlists (milestone 10d): staff and admins see a slot's waitlist;
 * admins manage it (offer now, remove). Customers join and leave their own
 * through the account area (scoped lookups and JoinWaitlist/LeaveWaitlist).
 */
final class WaitlistEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function view(User $user, WaitlistEntry $entry): bool
    {
        return $user->isTeamMember() || $entry->user_id === $user->id;
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WaitlistEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, WaitlistEntry $entry): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
