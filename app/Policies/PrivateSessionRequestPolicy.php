<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PrivateSessionRequest;
use App\Models\User;

/**
 * Private session requests (prompt 15B): admins and staff read them in the
 * panel; only admins approve or decline (it creates a slot and an order).
 * Customers see and withdraw their own on the account pages. Never edited
 * by hand: changes go through the PrivateRequests actions.
 */
final class PrivateSessionRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function view(User $user, PrivateSessionRequest $request): bool
    {
        return $user->isTeamMember() || $request->user_id === $user->id;
    }

    public function decide(User $user): bool
    {
        return $user->isAdmin();
    }

    public function withdraw(User $user, PrivateSessionRequest $request): bool
    {
        return $request->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PrivateSessionRequest $request): bool
    {
        return false;
    }

    public function delete(User $user, PrivateSessionRequest $request): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
