<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Booking\SlotLock;
use App\Enums\WaitlistStatus;
use App\Exceptions\BookingRejected;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;

/**
 * The customer leaves a waitlist, or an admin removes them with a reason
 * (milestone 10d). Seats held for their offer go straight to the next
 * person in line.
 */
final class LeaveWaitlist
{
    public function __construct(
        private readonly SlotLock $slotLock,
        private readonly ChangeWaitlistStatus $status,
        private readonly OfferWaitlistSeats $offers,
    ) {}

    /**
     * @throws BookingRejected
     */
    public function handle(WaitlistEntry $entry, User $actor, ?string $reason = null): WaitlistEntry
    {
        $asAdmin = $entry->user_id !== $actor->id;
        $reason = trim((string) $reason);

        if ($asAdmin && ! $actor->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can remove someone from a waitlist.');
        }

        if ($asAdmin && $reason === '') {
            throw BookingRejected::notAllowed('Give a reason for removing them.');
        }

        DB::transaction(function () use ($entry, $actor, $asAdmin, $reason): void {
            $this->slotLock->acquire($entry->slot_id);
            $entry->refresh();

            if (! $entry->status->isActive()) {
                throw BookingRejected::notAllowed('This waitlist place is no longer active.');
            }

            $this->status->handle($entry, WaitlistStatus::Cancelled, $actor, $asAdmin ? $reason : 'Left the waitlist');
        });

        $this->offers->handle($entry->slot_id, $actor);

        return $entry;
    }
}
