<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

/**
 * Staff and admins read bookings (check-in arrives in milestone 8);
 * trainers work the bookings of their own slots from the run sheet
 * (milestone 10b); customers see their own. Bookings are created only by fulfilment.
 */
final class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeamMember();
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->isTeamMember() || $booking->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Customers change (cancel, reschedule) their own credit bookings for
     * sessions that haven't started; paid bookings change through an
     * admin. The actions enforce the cutoff and credit rules.
     */
    public function change(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id
            && $booking->usesCredit()
            && $booking->status === BookingStatus::Confirmed
            && $booking->slot->starts_at->isFuture();
    }

    /**
     * Run sheet and gate work (milestone 10b): check in or mark no-show,
     * assign a horse, write the session note. The office team for every
     * booking; a trainer only for bookings in their own slots.
     */
    public function checkIn(User $user, Booking $booking): bool
    {
        return $this->worksSession($user, $booking);
    }

    public function assignHorse(User $user, Booking $booking): bool
    {
        return $this->worksSession($user, $booking) && $booking->status !== BookingStatus::Cancelled;
    }

    public function writeNote(User $user, Booking $booking): bool
    {
        return $this->worksSession($user, $booking) && $booking->status !== BookingStatus::Cancelled;
    }

    /**
     * Tick skills and write the customer's progress note (milestone 10d):
     * the same people as the run sheet, once the session has started, for
     * a rider who wasn't cancelled or marked no-show.
     */
    public function recordProgress(User $user, Booking $booking): bool
    {
        return $this->worksSession($user, $booking)
            && in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Attended], true)
            && ! $booking->slot->starts_at->isFuture();
    }

    /** Admin booking tools: manual bookings, moves, cancellations, attendance. */
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Booking $booking): bool
    {
        return false;
    }

    public function delete(User $user, Booking $booking): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function worksSession(User $user, Booking $booking): bool
    {
        return $user->isTeamMember() || ($user->isTrainer() && $booking->slot->trainer_id === $user->id);
    }
}
