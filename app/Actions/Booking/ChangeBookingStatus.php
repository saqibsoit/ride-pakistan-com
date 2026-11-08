<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\BookingRejected;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single place a booking's status changes (BookingStatus transitions),
 * with the audit log. Attended and no-show only once the session has
 * started. Cancelling goes through CancelBooking, which handles credits.
 */
final class ChangeBookingStatus
{
    /**
     * @throws InvalidStatusTransition
     * @throws BookingRejected
     */
    /**
     * @param  bool  $earlyCheckIn  attended may be recorded before the start (gate check-in window, CheckInBooking)
     */
    public function handle(Booking $booking, BookingStatus $to, ?User $actor = null, ?string $reason = null, bool $earlyCheckIn = false): Booking
    {
        return DB::transaction(function () use ($booking, $to, $actor, $reason, $earlyCheckIn): Booking {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between('Booking '.$locked->ticket_code, $from->value, $to->value);
            }

            if (in_array($to, [BookingStatus::Attended, BookingStatus::NoShow], true)
                && ! ($earlyCheckIn && $to === BookingStatus::Attended)
                && $locked->slot->starts_at->isAfter(CarbonImmutable::now())) {
                throw BookingRejected::notAllowed('Attendance can be recorded once the session has started.');
            }

            $locked->status = $to;

            if ($to === BookingStatus::Cancelled) {
                $locked->cancelled_at = CarbonImmutable::now();
            }

            $locked->save();

            activity('bookings')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'reason' => $reason])
                ->log(sprintf('Booking %s: %s → %s', $locked->ticket_code, $from->label(), $to->label()));

            $booking->setRawAttributes($locked->getAttributes(), true);

            return $booking;
        });
    }
}
