<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\BookingStatus;
use App\Enums\CalendarMethod;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Calendar\IcsCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /account/bookings/{ticket}/calendar.ics (milestone 10d): the
 * customer's own booking as an Apple Calendar / Outlook file. Scoped to the
 * signed-in customer (another customer's ticket is a 404), because it
 * carries the map pin. A cancelled booking downloads a cancelling file.
 */
final class BookingCalendarController extends Controller
{
    public function __invoke(Request $request, string $ticket, IcsCalendar $ics): Response
    {
        /** @var User $user */
        $user = $request->user();

        $booking = $user->bookings()->where('ticket_code', $ticket)->with(['slot', 'rider'])->firstOrFail();
        $method = $booking->status === BookingStatus::Cancelled ? CalendarMethod::Cancel : CalendarMethod::Publish;

        return response($ics->forBookings([$booking], $method), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.IcsCalendar::filename($booking).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
