<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Enums\CalendarMethod;
use App\Models\Booking;
use App\Support\Calendar\GoogleCalendarLink;
use App\Support\Calendar\IcsCalendar;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Adds the session(s) as an .ics attachment (Apple Calendar, Outlook) and
 * gives the mail view "Add to Google Calendar" links (milestone 10d).
 */
trait AttachesCalendar
{
    /**
     * @param  iterable<Booking>  $bookings
     */
    protected function attachCalendar(MailMessage $mail, iterable $bookings, CalendarMethod $method = CalendarMethod::Publish): MailMessage
    {
        return $mail->attachData(
            app(IcsCalendar::class)->forBookings($bookings, $method),
            $method === CalendarMethod::Cancel ? 'ride-pakistan-cancelled.ics' : 'ride-pakistan-session.ics',
            ['mime' => 'text/calendar; charset=utf-8; method='.$method->value],
        );
    }

    /**
     * @param  iterable<Booking>  $bookings
     * @return array<int, string> Google Calendar link by booking id
     */
    protected function googleLinks(iterable $bookings): array
    {
        $links = [];
        $google = app(GoogleCalendarLink::class);

        foreach ($bookings as $booking) {
            $links[$booking->id] = $google->for($booking);
        }

        return $links;
    }
}
