<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\CancellationCutoff;
use App\Actions\Booking\RescheduleBooking;
use App\Exceptions\BookingRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RescheduleBookingRequest;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Slot;
use App\Models\User;
use App\Support\Availability;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Customers cancel or reschedule their credit bookings (PLAN §2). Lookups
 * are scoped to the signed-in customer (another customer's ticket is a
 * 404); the BookingPolicy "change" ability and the actions do the rest.
 */
final class BookingChangeController extends Controller
{
    public function editReschedule(Request $request, string $ticket, Availability $availability, CancellationCutoff $cutoff): View
    {
        $booking = $this->ownBooking($request, $ticket);
        $pack = CreditPack::query()->find($booking->credit_pack_id);

        $slots = $availability->upcoming(24, Availability::TYPE_GROUP, 8)
            ->reject(fn (Slot $slot): bool => $slot->id === $booking->slot_id)
            ->filter(fn (Slot $slot): bool => $pack === null || $pack->covers($slot))
            ->values();

        return view('account.booking-reschedule', [
            'booking' => $booking,
            'slots' => $slots,
            'canMove' => $cutoff->isBefore($booking->slot),
            'cutoffHours' => $cutoff->hours(),
        ]);
    }

    public function reschedule(RescheduleBookingRequest $request, string $ticket, RescheduleBooking $reschedule): RedirectResponse
    {
        $booking = $this->ownBooking($request, $ticket);

        /** @var User $user */
        $user = $request->user();

        try {
            $reschedule->handle($booking, $request->integer('slot'), $user);
        } catch (BookingRejected $e) {
            return back()->withErrors(['slot' => $e->getMessage()]);
        }

        return redirect()->route('account.bookings')->with('status', 'booking-moved');
    }

    public function confirmCancel(Request $request, string $ticket, CancellationCutoff $cutoff): View
    {
        $booking = $this->ownBooking($request, $ticket);

        return view('account.booking-cancel', [
            'booking' => $booking,
            'creditBack' => $cutoff->isBefore($booking->slot),
            'cutoffHours' => $cutoff->hours(),
            'deadline' => $cutoff->deadline($booking->slot),
        ]);
    }

    public function cancel(Request $request, string $ticket, CancelBooking $cancel): RedirectResponse
    {
        $booking = $this->ownBooking($request, $ticket);

        /** @var User $user */
        $user = $request->user();

        try {
            $returned = $cancel->handle($booking, $user);
        } catch (BookingRejected $e) {
            return redirect()->route('account.bookings')->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('account.bookings')->with('status', $returned ? 'booking-cancelled-credit-back' : 'booking-cancelled');
    }

    private function ownBooking(Request $request, string $ticket): Booking
    {
        /** @var User $user */
        $user = $request->user();

        $booking = $user->bookings()->where('ticket_code', $ticket)->with(['slot', 'rider'])->firstOrFail();

        Gate::authorize('change', $booking);

        return $booking;
    }
}
