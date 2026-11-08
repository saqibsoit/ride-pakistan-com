<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Leads (PLAN milestone 9): staff and admins work the pipeline (add, move,
 * assign, note). Only admins convert a lead to a customer, delete leads
 * and see revenue reports.
 */
final class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->isTeamMember();
    }

    public function create(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->isTeamMember();
    }

    public function convert(User $user, Lead $lead): bool
    {
        return $user->isAdmin() && $lead->user_id === null;
    }

    public function viewReports(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
