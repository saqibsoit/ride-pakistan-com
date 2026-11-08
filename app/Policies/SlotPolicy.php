<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Slot;
use App\Models\User;

/**
 * Staff and admins see slots. Rule slots come from the generator; admins
 * can add manual slots (CreateManualSlot) and close, reopen or cancel any
 * slot (ChangeSlotStatus). Nobody edits or deletes slots directly.
 */
final class SlotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function view(User $user, Slot $slot): bool
    {
        return $user->isTeamMember();
    }

    /** Add a manual slot. */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Slot $slot): bool
    {
        return false;
    }

    public function delete(User $user, Slot $slot): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Close, reopen or cancel. */
    public function changeStatus(User $user, Slot $slot): bool
    {
        return $user->isAdmin();
    }

    /** Run the slot generator now. */
    public function generate(User $user): bool
    {
        return $user->isAdmin();
    }
}
