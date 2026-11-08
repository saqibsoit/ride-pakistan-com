<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Skill;
use App\Models\User;

/**
 * The rider skill checklist (milestone 10d): admins manage it; staff can
 * read it. A skill riders have reached can't be deleted, only switched off.
 */
final class SkillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function view(User $user, Skill $skill): bool
    {
        return $user->isTeamMember();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Skill $skill): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Skill $skill): bool
    {
        return $user->isAdmin() && ! $skill->riderSkills()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $user->isAdmin();
    }
}
