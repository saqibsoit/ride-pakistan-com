<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

/**
 * Staff and admins read bookings (check-in arrives in milestone 8);
 * customers see their own. Bookings are created only by fulfilment.
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
}
