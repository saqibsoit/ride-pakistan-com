<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Booking\CheckInBooking;
use App\Enums\BookingStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * /checkin/{code} (PLAN §6): staff scan a ticket QR at the gate and record
 * attended or no-show. Mobile-first, never cached, noindex. Access is
 * checked by EnsureCheckInAccess (team only, MFA set up).
 */
final class CheckInController extends Controller
{
    public function show(string $code, CheckInBooking $checkIn): View|Response
    {
        $booking = $this->find($code);

        if ($booking === null) {
            return response()->view('checkin.not-found', ['code' => $code], 404);
        }

        Gate::authorize('checkIn', $booking);

        $lastChange = Activity::query()
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->id)
            ->where('event', 'status_changed')
            ->latest('id')
            ->with('causer')
            ->first();

        return view('checkin.show', [
            'booking' => $booking,
            'state' => $checkIn->state($booking),
            'opensAt' => $checkIn->opensAt($booking),
            'canNoShow' => $checkIn->canMarkNoShow($booking),
            'lastChange' => $lastChange,
            'pack' => $booking->creditPack,
        ]);
    }

    public function store(Request $request, string $code, CheckInBooking $checkIn): RedirectResponse
    {
        $booking = $this->find($code);
        abort_if($booking === null, 404);
        Gate::authorize('checkIn', $booking);

        $to = BookingStatus::tryFrom((string) $request->input('status'));

        /** @var User $user */
        $user = $request->user();

        if ($to === null) {
            return back()->with('checkin_error', 'Choose checked in or no-show.');
        }

        if ($booking->status === $to) {
            return redirect()->route('checkin.show', $code)
                ->with('checkin_error', $to === BookingStatus::Attended ? 'Already checked in. Nothing changed.' : 'No-show was already recorded.');
        }

        try {
            $checkIn->handle($booking, $user, $to);
        } catch (BookingRejected $e) {
            return redirect()->route('checkin.show', $code)->with('checkin_error', $e->getMessage());
        }

        return redirect()->route('checkin.show', $code)
            ->with('checkin_done', $to === BookingStatus::Attended ? 'Checked in: '.$booking->rider->name : 'No-show recorded for '.$booking->rider->name);
    }

    private function find(string $code): ?Booking
    {
        return Booking::query()
            ->where('ticket_code', strtoupper(trim($code)))
            ->with(['slot', 'rider', 'user', 'order.product', 'creditPack.order.product'])
            ->first();
    }
}
