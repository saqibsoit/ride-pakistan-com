<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QuickReply;
use App\Models\User;

/**
 * Quick replies (milestone 10c): staff and admins read and copy them while
 * answering leads; only admins write them. Trainers don't work leads.
 */
final class QuickReplyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function view(User $user, QuickReply $reply): bool
    {
        return $user->isTeamMember();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, QuickReply $reply): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, QuickReply $reply): bool
    {
        return $user->isAdmin();
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
