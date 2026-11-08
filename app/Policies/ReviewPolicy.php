<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

/**
 * Reviews (milestone 10d): staff and admins read them in the panel; only
 * admins moderate (approve, hide, reply). Nobody edits a customer's words
 * or creates reviews from the panel.
 */
final class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function view(User $user, Review $review): bool
    {
        return $user->isTeamMember();
    }

    public function moderate(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Review $review): bool
    {
        return false;
    }

    public function delete(User $user, Review $review): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
