<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\CreateManualBooking;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Settings\UpdateSettings;
use App\Enums\CalendarMethod;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingConfirmed;
use App\Notifications\BookingMoved;
use App\Notifications\OrderConfirmed;
use App\Support\Calendar\GoogleCalendarLink;
use App\Support\Calendar\IcsCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

const CAL_PIN = 'https://maps.example.com/?q=33.70,73.05-cal-pin';

/** A customer with one credit booking $days ahead at 08:00 Pakistan time. */
function calendarCreditBooking(int $days = 5, string $riderName = 'Ayesha Khan'): Booking
{
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create(['name' => $riderName]);
    CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(4)]);

    return app(BookWithCredit::class)->handle($user, nearSlot($days)->id, [$rider->id])->sole();
}

/**
 * Minimal RFC 5545 reader for the tests: checks line endings and folding,
 * unfolds, and returns the content lines as [name;params, value] pairs.
 *
 * @return list<array{0: string, 1: string}>
 */
function icsLines(string $ics): array
{
    expect($ics)->toEndWith("\r\n")
        ->and(preg_match("/(?<!\r)\n/", $ics))->toBe(0); // only CRLF line breaks

    foreach (explode("\r\n", rtrim($ics, "\r\n")) as $raw) {
        expect(strlen($raw))->toBeLessThanOrEqual(75);
    }

    $unfolded = preg_replace("/\r\n[ \t]/", '', $ics);
    $lines = [];

    foreach (explode("\r\n", rtrim((string) $unfolded, "\r\n")) as $line) {
        [$name, $value] = explode(':', $line, 2);
        $lines[] = [$name, $value];
    }

    return $lines;
}

/** @return array<string, string> first value of each property in the VEVENT */
function icsEvent(string $ics): array
{
    $props = [];
    $inside = false;

    foreach (icsLines($ics) as [$name, $value]) {
        if ($name === 'BEGIN' && $value === 'VEVENT') {
            $inside = true;
        } elseif ($name === 'END' && $value === 'VEVENT') {
            break;
        } elseif ($inside) {
            $props[explode(';', $name)[0]] ??= $value;
            $props[$name] ??= $value;
        }
    }

    return $props;
}

beforeEach(function (): void {
    app(UpdateSettings::class)->handle([SettingKey::MapPinUrl->value => CAL_PIN]);
});

it('writes a valid calendar file with Pakistan times, Islamabad and a stable UID', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Karachi'));
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create(['name' => 'Ali, "Junior"; Raza']);
    $slot = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create();
    $booking = Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'user_id' => $user->id]);

    $ics = app(IcsCalendar::class)->forBookings([$booking]);
    $lines = icsLines($ics);
    $event = icsEvent($ics);

    $names = array_map(fn (array $l): string => $l[0].':'.$l[1], $lines);
    expect($names[0])->toBe('BEGIN:VCALENDAR')
        ->and(end($names))->toBe('END:VCALENDAR')
        ->and($names)->toContain('VERSION:2.0', 'METHOD:PUBLISH', 'BEGIN:VTIMEZONE', 'TZID:Asia/Karachi', 'TZOFFSETTO:+0500')
        ->and(collect($lines)->where(0, 'BEGIN')->count())->toBe(collect($lines)->where(0, 'END')->count());

    expect($event['UID'])->toBe('booking-'.$booking->ticket_code.'@'.parse_url((string) config('app.url'), PHP_URL_HOST))
        ->and($event['DTSTART;TZID=Asia/Karachi'])->toBe('20261114T080000')
        ->and($event['DTEND;TZID=Asia/Karachi'])->toBe('20261114T100000')
        ->and($event['DTSTAMP'])->toMatch('/^\d{8}T\d{6}Z$/')
        ->and($event['SEQUENCE'])->toBe('0')
        ->and($event['STATUS'])->toBe('CONFIRMED')
        ->and($event['LOCATION'])->toBe('Islamabad')
        ->and($event['SUMMARY'])->toBe('Horse riding: Ali\, "Junior"\; Raza (Ride Pakistan)')
        ->and($event['DESCRIPTION'])->toContain('Ticket: '.$booking->ticket_code)
        ->and($event['DESCRIPTION'])->toContain(str_replace([',', ';'], ['\,', '\;'], CAL_PIN))
        ->and($event)->toHaveKey('ORGANIZER;CN=Ride Pakistan');
});

it('folds long lines without splitting characters', function (): void {
    $line = 'DESCRIPTION:'.str_repeat('ریڈ پاکستان ', 20);
    $folded = IcsCalendar::fold($line);

    foreach (explode("\r\n", $folded) as $part) {
        expect(strlen($part))->toBeLessThanOrEqual(75)->and(mb_check_encoding($part, 'UTF-8'))->toBeTrue();
    }

    expect(preg_replace("/\r\n /", '', $folded))->toBe($line);
});

it('leaves the pin out when none is set and for cancellations', function (): void {
    app(UpdateSettings::class)->handle([SettingKey::MapPinUrl->value => '']);
    $booking = calendarCreditBooking();

    expect(icsEvent(app(IcsCalendar::class)->forBookings([$booking]))['DESCRIPTION'])->not->toContain('pin');

    app(UpdateSettings::class)->handle([SettingKey::MapPinUrl->value => CAL_PIN]);
    $cancel = app(IcsCalendar::class)->forBookings([$booking], CalendarMethod::Cancel);

    expect($cancel)->toContain("METHOD:CANCEL\r\n")
        ->and(icsEvent($cancel)['STATUS'])->toBe('CANCELLED')
        ->and(icsEvent($cancel)['SEQUENCE'])->toBe('1')
        ->and($cancel)->not->toContain('cal-pin');
});

it('builds a Google Calendar link in UTC with the Pakistan time zone', function (): void {
    $user = User::factory()->create();
    $slot = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 15:00', 'Asia/Karachi'))->create();
    $booking = Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => Rider::factory()->for($user), 'user_id' => $user->id]);

    $url = app(GoogleCalendarLink::class)->for($booking);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://calendar.google.com/calendar/render?')
        ->and($query['action'])->toBe('TEMPLATE')
        ->and($query['dates'])->toBe('20261114T100000Z/20261114T120000Z')
        ->and($query['ctz'])->toBe('Asia/Karachi')
        ->and($query['location'])->toBe('Islamabad')
        ->and($query['details'])->toContain(CAL_PIN);
});

it('lets customers download only their own booking as .ics', function (): void {
    $booking = calendarCreditBooking();

    $response = $this->actingAs($booking->user)->get(route('account.bookings.calendar', $booking->ticket_code))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/calendar')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename="ride-pakistan-session-'.$booking->ticket_code.'.ics"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(icsEvent((string) $response->getContent())['UID'])->toBe(IcsCalendar::uid($booking));

    $this->actingAs(User::factory()->create())->get(route('account.bookings.calendar', $booking->ticket_code))->assertNotFound();
    auth()->logout();
    $this->get(route('account.bookings.calendar', $booking->ticket_code))->assertRedirect(route('login'));
});

it('shows Google and .ics links on upcoming bookings', function (): void {
    $booking = calendarCreditBooking();

    $this->actingAs($booking->user)->get('/account/bookings')->assertOk()
        ->assertSee('calendar.google.com/calendar/render', false)
        ->assertSee(route('account.bookings.calendar', $booking->ticket_code), false);
});

it('attaches the .ics and Google links to the paid booking confirmation', function (): void {
    $user = User::factory()->create();
    $riders = Rider::factory()->count(2)->for($user)->create()->modelKeys();
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(), nearSlot(4)->id, array_map('intval', $riders));
    $order = app(FulfilOrder::class)->handle($order)->refresh();

    $mail = (new OrderConfirmed($order))->toMail($user);
    $ics = collect($mail->rawAttachments)->firstWhere('name', 'ride-pakistan-session.ics');

    expect($ics)->not->toBeNull()
        ->and($ics['options']['mime'])->toBe('text/calendar; charset=utf-8; method=PUBLISH')
        ->and(substr_count($ics['data'], 'BEGIN:VEVENT'))->toBe(2)
        ->and((string) $mail->render())->toContain('calendar.google.com');
});

it('emails a confirmation with tickets and .ics for credit and no-charge bookings', function (): void {
    Notification::fake();
    $booking = calendarCreditBooking();

    Notification::assertSentTo($booking->user, BookingConfirmed::class, function (BookingConfirmed $n) use ($booking): bool {
        $mail = $n->toMail($booking->user);
        $html = (string) $mail->render();

        return $n->bookings->sole()->is($booking)
            && str_contains($html, $booking->ticket_code)
            && str_contains($html, CAL_PIN)
            && collect($mail->rawAttachments)->contains('name', 'ride-pakistan-session.ics')
            && collect($mail->rawAttachments)->contains('name', 'ticket-'.$booking->ticket_code.'.png');
    });

    $rider = Rider::factory()->create();
    $manual = app(CreateManualBooking::class)->handle(User::factory()->admin()->create(), $rider, nearSlot(6)->id, null, 'Friend of the yard');

    Notification::assertSentTo($rider->user, BookingConfirmed::class, fn (BookingConfirmed $n): bool => $n->bookings->sole()->is($manual));
});

it('emails an updated .ics with a higher sequence when a booking moves', function (): void {
    Notification::fake();
    $booking = calendarCreditBooking(5);
    $from = $booking->slot;
    $target = nearSlot(6);

    app(RescheduleBooking::class)->handle($booking, $target->id, $booking->user);

    expect($booking->fresh()?->calendar_sequence)->toBe(1);

    Notification::assertSentTo($booking->user, BookingMoved::class, function (BookingMoved $n) use ($booking, $from, $target): bool {
        $ics = collect($n->toMail($booking->user)->rawAttachments)->firstWhere('name', 'ride-pakistan-session.ics')['data'];
        $event = icsEvent($ics);

        return $n->from->is($from)
            && $event['UID'] === IcsCalendar::uid($booking)
            && $event['SEQUENCE'] === '1'
            && $event['DTSTART;TZID=Asia/Karachi'] === $target->localStartsAt()->format('Ymd\THis');
    });

    // A second move is a new email with sequence 2.
    app(RescheduleBooking::class)->handle($booking->fresh() ?? $booking, nearSlot(7)->id, $booking->user);
    Notification::assertSentToTimes($booking->user, BookingMoved::class, 2);
    expect($booking->fresh()?->calendar_sequence)->toBe(2);
});

it('emails a cancelling .ics when a booking is cancelled', function (): void {
    Notification::fake();
    $booking = calendarCreditBooking(5);

    app(CancelBooking::class)->handle($booking, $booking->user);

    Notification::assertSentTo($booking->user, BookingCancelled::class, function (BookingCancelled $n) use ($booking): bool {
        $mail = $n->toMail($booking->user);
        $ics = collect($mail->rawAttachments)->firstWhere('name', 'ride-pakistan-cancelled.ics');

        return $n->creditReturned
            && $ics['options']['mime'] === 'text/calendar; charset=utf-8; method=CANCEL'
            && icsEvent($ics['data'])['STATUS'] === 'CANCELLED'
            && icsEvent($ics['data'])['UID'] === IcsCalendar::uid($booking)
            && str_contains((string) $mail->render(), 'credit is back');
    });

    $this->actingAs($booking->user)->get(route('account.bookings.calendar', $booking->ticket_code))
        ->assertOk()->assertSee('METHOD:CANCEL', false);
});
