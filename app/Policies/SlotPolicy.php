<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Slot;
use App\Models\User;

/**
 * Staff and admins see slots. Rule slots come from the generator; admins
 * can add manual slots (CreateManualSlot) and close, reopen or cancel any
 * slot (ChangeSlotStatus), and edit manual slots (UpdateManualSlot).
 * Nobody deletes slots; rule slots follow their schedule rule.
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

    /** Edit a manual slot's time, capacity or private-only flag (UpdateManualSlot). */
    public function update(User $user, Slot $slot): bool
    {
        return $user->isAdmin() && $slot->isManual();
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
