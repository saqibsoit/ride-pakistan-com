<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeadNote;
use App\Models\User;

/** Lead notes follow their lead: the team reads and adds; admins delete. */
final class LeadNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function view(User $user, LeadNote $note): bool
    {
        return $user->isTeamMember();
    }

    public function create(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function update(User $user, LeadNote $note): bool
    {
        return false;
    }

    public function delete(User $user, LeadNote $note): bool
    {
        return $user->isAdmin();
    }
}
