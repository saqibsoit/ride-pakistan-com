<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Booking\LockedSlot;
use App\Enums\WaitlistStatus;
use App\Models\User;
use App\Models\WaitlistEntry;

/**
 * A customer who is on a slot's waitlist has just booked or held seats in
 * it (milestone 10d). Called by HoldSeats and BookWithCredit inside their
 * transaction, under the slot lock taken with SlotLock $claimFor: the
 * customer's entries become "booked" and their offer hold is released
 * (its seats were counted as theirs and now sit in the new hold or
 * bookings). Seats they offered but didn't take go to the next person on
 * the next waitlist run.
 */
final class ClaimWaitlistPlace
{
    public function __construct(private readonly ChangeWaitlistStatus $status) {}

    public function handle(LockedSlot $slot, User $user): void
    {
        foreach ($slot->waitlistEntryIds as $id) {
            $entry = WaitlistEntry::query()->find($id);

            if ($entry !== null && $entry->status->canTransitionTo(WaitlistStatus::Booked)) {
                $this->status->handle($entry, WaitlistStatus::Booked, $user);
            }
        }
    }
}
