<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Rider;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin booking for a customer (phone or walk-in). Either paid with one of
 * the customer's credits (BookWithCredit), or a no-charge booking with a
 * required reason. Never creates or fulfils an order. Same seat rules and
 * slot lock as every other booking. Logged.
 */
final class CreateManualBooking
{
    public function __construct(
        private readonly SlotLock $slotLock,
        private readonly BookWithCredit $bookWithCredit,
        private readonly GenerateTicketCode $tickets,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @throws BookingRejected
     */
    public function handle(User $admin, Rider $rider, int $slotId, ?CreditPack $pack = null, ?string $reason = null): Booking
    {
        if (! $admin->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can add manual bookings.');
        }

        $customer = $rider->user;

        if ($pack !== null) {
            $booking = $this->bookWithCredit->handle($customer, $slotId, [$rider->id], $admin, $pack)->first();

            return $booking ?? throw BookingRejected::noCredits();
        }

        $reason = trim((string) $reason);

        if ($reason === '') {
            throw BookingRejected::notAllowed('Give a reason for a no-charge booking.');
        }

        $booking = DB::transaction(function () use ($admin, $rider, $customer, $slotId, $reason): Booking {
            $slot = $this->slotLock->acquire($slotId);
            $slot->assertBookable();
            $slot->assertRoomFor([$rider->id], private: false);

            $booking = Booking::query()->create([
                'slot_id' => $slotId,
                'order_id' => null,
                'rider_id' => $rider->id,
                'user_id' => $customer->id,
                'credit_pack_id' => null,
                'ticket_code' => $this->tickets->handle(),
            ]);

            activity('bookings')
                ->performedOn($booking)
                ->causedBy($admin)
                ->event('manual_booking')
                ->withProperties(['reason' => $reason, 'charge' => 'none'])
                ->log('No-charge booking for '.$slot->slot->label());

            return $booking;
        });

        $this->notifier->send($customer, new BookingConfirmed(new Collection([$booking])), Notifier::key(BookingConfirmed::type(), $booking, $customer));

        return $booking;
    }
}
