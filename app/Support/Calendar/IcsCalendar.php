<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\BookingStatus;
use App\Enums\CalendarMethod;
use App\Enums\SettingKey;
use App\Models\Booking;
use App\Models\Slot;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * Writes RFC 5545 calendar files for a customer's bookings (milestone 10d).
 * One VEVENT per booking, UID "booking-{ticket}@host", so a later file for
 * the same booking (moved: higher SEQUENCE; cancelled: METHOD:CANCEL)
 * replaces the first one. Times carry TZID=Asia/Karachi with a matching
 * VTIMEZONE. The location is "Islamabad"; the exact map pin goes only in
 * the description of a confirmed booking, and these files are only ever
 * sent or served to the booking's own customer.
 */
final class IcsCalendar
{
    private const PRODID = '-//Ride Pakistan//Bookings//EN';

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  iterable<Booking>  $bookings
     */
    public function forBookings(iterable $bookings, CalendarMethod $method = CalendarMethod::Publish): string
    {
        $bookings = Collection::make($bookings);
        $zone = Slot::displayTimezone();

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method->value,
            ...$this->timezone($zone, $bookings),
        ];

        foreach ($bookings as $booking) {
            array_push($lines, ...$this->event($booking, $method, $zone));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    public static function uid(Booking $booking): string
    {
        return 'booking-'.$booking->ticket_code.'@'.self::host();
    }

    /** "ride-pakistan-session-{ticket}.ics" */
    public static function filename(Booking $booking): string
    {
        return 'ride-pakistan-session-'.$booking->ticket_code.'.ics';
    }

    public static function summary(Booking $booking): string
    {
        return 'Horse riding: '.$booking->rider->name.' (Ride Pakistan)';
    }

    /** Plain-text details shared by the .ics description and the Google link. */
    public function description(Booking $booking, bool $cancelled = false): string
    {
        $parts = [
            ($cancelled ? 'Cancelled: ' : '').'Ride Pakistan session for '.$booking->rider->name.', '.$booking->slot->label().' (Pakistan time).',
            'Ticket: '.$booking->ticket_code,
        ];

        $pin = $this->settings->string(SettingKey::MapPinUrl);

        if (! $cancelled && $pin !== '' && $booking->status === BookingStatus::Confirmed) {
            $parts[] = 'Location pin (please do not share): '.$pin;
        }

        $parts[] = 'Your bookings: '.route('account.bookings');

        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function event(Booking $booking, CalendarMethod $method, string $zone): array
    {
        $booking->loadMissing(['slot', 'rider']);
        $slot = $booking->slot;
        $cancelled = $method === CalendarMethod::Cancel;
        $local = fn (CarbonImmutable $at): string => $at->setTimezone($zone)->format('Ymd\THis');

        return [
            'BEGIN:VEVENT',
            'UID:'.self::uid($booking),
            'DTSTAMP:'.CarbonImmutable::now('UTC')->format('Ymd\THis\Z'),
            'SEQUENCE:'.($booking->calendar_sequence + ($cancelled ? 1 : 0)),
            'STATUS:'.($cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'DTSTART;TZID='.$zone.':'.$local($slot->starts_at),
            'DTEND;TZID='.$zone.':'.$local($slot->ends_at),
            'SUMMARY:'.self::escape(($cancelled ? 'Cancelled: ' : '').self::summary($booking)),
            'LOCATION:Islamabad',
            'DESCRIPTION:'.self::escape($this->description($booking, $cancelled)),
            'URL:'.route('account.tickets.show', $booking->ticket_code),
            'ORGANIZER;CN=Ride Pakistan:mailto:'.(string) config('mail.from.address'),
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ];
    }

    /**
     * A fixed-offset VTIMEZONE for the display zone (Asia/Karachi has had
     * no daylight saving since 2009), from the offset at the first session.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return list<string>
     */
    private function timezone(string $zone, Collection $bookings): array
    {
        /** @var Booking|null $first */
        $first = $bookings->first();
        $at = $first?->slot->starts_at ?? CarbonImmutable::now();
        $seconds = (new DateTimeZone($zone))->getOffset($at);
        $offset = ($seconds < 0 ? '-' : '+').sprintf('%02d%02d', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
        $name = $at->setTimezone($zone)->format('T');

        return [
            'BEGIN:VTIMEZONE',
            'TZID:'.$zone,
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:'.$offset,
            'TZOFFSETTO:'.$offset,
            'TZNAME:'.self::escape($name),
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    /** RFC 5545 §3.3.11 TEXT escaping. */
    public static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $text);
    }

    /** Folds a content line at 75 octets without splitting a UTF-8 character (RFC 5545 §3.1). */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = [];
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
                $limit = 74; // continuation lines start with a space
            }

            $current .= $char;
        }

        $out[] = $current;

        return implode("\r\n ", $out);
    }

    private static function host(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'ride-pakistan.com');
    }
}
