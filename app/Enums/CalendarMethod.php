<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * iCalendar METHOD of a .ics file (milestone 10d): PUBLISH adds or updates
 * the session in the customer's calendar, CANCEL removes it.
 */
enum CalendarMethod: string
{
    case Publish = 'PUBLISH';
    case Cancel = 'CANCEL';
}
