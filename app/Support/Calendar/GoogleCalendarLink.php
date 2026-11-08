<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Models\Booking;
use App\Models\Slot;

/**
 * "Add to Google Calendar" link for a booking (milestone 10d). Times in
 * UTC with ctz set to Pakistan time; location "Islamabad"; the details
 * (with the map pin for a confirmed booking) are the same as the .ics, so
 * the link is only shown to the booking's own customer.
 */
final class GoogleCalendarLink
{
    public function __construct(private readonly IcsCalendar $ics) {}

    public function for(Booking $booking): string
    {
        $booking->loadMissing(['slot', 'rider']);
        $utc = fn ($at): string => $at->setTimezone('UTC')->format('Ymd\THis\Z');

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => IcsCalendar::summary($booking),
            'dates' => $utc($booking->slot->starts_at).'/'.$utc($booking->slot->ends_at),
            'ctz' => Slot::displayTimezone(),
            'location' => 'Islamabad',
            'details' => $this->ics->description($booking),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
