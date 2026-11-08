<?php

declare(strict_types=1);

use App\Actions\Notifications\ResendNotification;
use App\Enums\BookingStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\Slot;
use App\Models\User;
use App\Support\TicketQr;
use Carbon\CarbonImmutable;
use chillerlan\QRCode\QRCode;
use Spatie\Activitylog\Models\Activity;

/** A confirmed booking for a session starting at $local (Pakistan time). */
function gateBooking(string $local = '2026-11-14 08:00', ?BookingStatus $status = null): Booking
{
    $slot = Slot::factory()->at(CarbonImmutable::parse($local, 'Asia/Karachi'))->create();

    return Booking::factory()->create(['slot_id' => $slot->id, 'status' => $status ?? BookingStatus::Confirmed]);
}

function atLocal(string $local): void
{
    test()->travelTo(CarbonImmutable::parse($local, 'Asia/Karachi'));
}

function staffMember(): User
{
    return User::factory()->staff()->withMfa()->create();
}

it('sends guests to the admin login and back afterwards', function (): void {
    $booking = gateBooking();

    $this->get(route('checkin.show', $booking->ticket_code))->assertRedirect('/test-admin/login');
    expect(session('url.intended'))->toBe(route('checkin.show', $booking->ticket_code));
});

it('refuses customers, even the ticket holder', function (): void {
    $booking = gateBooking();

    $this->actingAs($booking->user)->get(route('checkin.show', $booking->ticket_code))->assertForbidden();
    $this->actingAs($booking->user)->post(route('checkin.store', $booking->ticket_code), ['status' => 'attended'])->assertForbidden();

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('sends team members without MFA to finish setting it up', function (): void {
    $this->actingAs(User::factory()->staff()->create())
        ->get(route('checkin.show', gateBooking()->ticket_code))
        ->assertRedirect('/test-admin');
});

it('lets staff check a rider in from two hours before the start', function (): void {
    $booking = gateBooking('2026-11-14 08:00');
    $staff = staffMember();
    $this->actingAs($staff);

    atLocal('2026-11-14 05:59');
    $this->get(route('checkin.show', $booking->ticket_code))->assertOk()->assertSee('Too early')->assertSee('6:00 am')->assertDontSee('>Check in<', false);
    $this->post(route('checkin.store', $booking->ticket_code), ['status' => 'attended'])->assertSessionHas('checkin_error');
    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);

    atLocal('2026-11-14 06:00');
    $this->get(route('checkin.show', $booking->ticket_code))
        ->assertOk()
        ->assertSee($booking->rider->name)
        ->assertSee('Check in')
        ->assertDontSee('Mark as no-show'); // not before the start

    $this->post(route('checkin.store', $booking->ticket_code), ['status' => 'attended'])
        ->assertRedirect(route('checkin.show', $booking->ticket_code))
        ->assertSessionHas('checkin_done', 'Checked in: '.$booking->rider->name);

    expect($booking->refresh()->status)->toBe(BookingStatus::Attended)
        ->and(Activity::query()->where('subject_id', $booking->id)->where('event', 'status_changed')->latest('id')->first()?->causer_id)->toBe($staff->id);
});

it('warns about a ticket that is already checked in and does not change it', function (): void {
    $booking = gateBooking('2026-11-14 08:00');
    $staff = staffMember();
    $this->actingAs($staff);
    atLocal('2026-11-14 07:30');

    $this->post(route('checkin.store', $booking->ticket_code), ['status' => 'attended']);
    $this->get(route('checkin.show', $booking->ticket_code))->assertSee('Checked in: '.$booking->rider->name);

    // A second tap (or a second scan) changes nothing and says so.
    $this->post(route('checkin.store', $booking->ticket_code), ['status' => 'attended'])
        ->assertSessionHas('checkin_error', 'Already checked in. Nothing changed.');

    $this->get(route('checkin.show', $booking->ticket_code))->assertSee('Already checked in. Nothing changed.');
    $this->get(route('checkin.show', $booking->ticket_code))
        ->assertSee('Already checked in')
        ->assertSee('7:30 am')
        ->assertSee($staff->name);

    expect(Activity::query()->where('subject_id', $booking->id)->where('subject_type', $booking->getMorphClass())->where('event', 'status_changed')->count())->toBe(1);
});

it('allows no-show only after the start, and a late arrival afterwards', function (): void {
    $booking = gateBooking('2026-11-14 08:00');
    $this->actingAs(staffMember());

    atLocal('2026-11-14 07:59');
    $this->post(route('checkin.store', $booking->ticket_code), ['status' => 'no_show'])->assertSessionHas('checkin_error', 'No-show can be recorded once the session has started.');

    atLocal('2026-11-14 08:20');
    $this->get(route('checkin.show', $booking->ticket_code))->assertSee('Mark as no-show');
    $this->post(route('checkin.store', $booking->ticket_code), ['status' => 'no_show']);
    expect($booking->refresh()->status)->toBe(BookingStatus::NoShow);

    $this->get(route('checkin.show', $booking->ticket_code))->assertSee('No-show recorded for');
    $this->get(route('checkin.show', $booking->ticket_code))->assertSee('Marked as no-show');
    $this->post(route('checkin.store', $booking->ticket_code), ['status' => 'attended']);
    expect($booking->refresh()->status)->toBe(BookingStatus::Attended);
});

it('warns about the wrong day, an ended session and a cancelled ticket', function (): void {
    $booking = gateBooking('2026-11-14 08:00');
    $this->actingAs(staffMember());

    atLocal('2026-11-13 07:00');
    $this->get(route('checkin.show', $booking->ticket_code))->assertSee('Wrong date')->assertSee('Saturday 14 Nov 2026');
    $this->post(route('checkin.store', $booking->ticket_code), ['status' => 'attended'])->assertSessionHas('checkin_error', 'This ticket is for another day.');

    atLocal('2026-11-14 10:01');
    $this->get(route('checkin.show', $booking->ticket_code))->assertSee('Session ended');

    $cancelled = gateBooking('2026-11-15 08:00', BookingStatus::Cancelled);
    atLocal('2026-11-15 07:00');
    $this->get(route('checkin.show', $cancelled->ticket_code))->assertSee('Cancelled ticket')->assertDontSee('>Check in<', false);
    $this->post(route('checkin.store', $cancelled->ticket_code), ['status' => 'attended'])->assertSessionHas('checkin_error', 'This booking was cancelled.');

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($cancelled->refresh()->status)->toBe(BookingStatus::Cancelled);
});

it('uses the Pakistan date, not UTC', function (): void {
    // 03:00 in Pakistan is 22:00 UTC the day before.
    $booking = gateBooking('2026-11-14 03:00');
    $this->actingAs(staffMember());

    atLocal('2026-11-14 01:30'); // 20:30 UTC on the 13th
    $this->post(route('checkin.store', $booking->ticket_code), ['status' => 'attended']);

    expect($booking->refresh()->status)->toBe(BookingStatus::Attended);
});

it('shows a clear page for an unknown code', function (): void {
    $this->actingAs(staffMember());

    $this->get(route('checkin.show', 'NOPE12345678'))->assertNotFound()->assertSee('Ticket not found');
    $this->post(route('checkin.store', 'NOPE12345678'), ['status' => 'attended'])->assertNotFound();
});

it('lets admins check in too, and accepts lower-case codes', function (): void {
    $booking = gateBooking('2026-11-14 08:00');
    atLocal('2026-11-14 07:00');

    $this->actingAs(User::factory()->admin()->withMfa()->create())
        ->post(route('checkin.store', strtolower($booking->ticket_code)), ['status' => 'attended']);

    expect($booking->refresh()->status)->toBe(BookingStatus::Attended);
});

it('gives staff check-in and nothing else', function (): void {
    $staff = staffMember();
    $booking = gateBooking();

    expect($staff->can('check-in'))->toBeTrue()
        ->and($staff->can('manage', Booking::class))->toBeFalse()
        ->and(fn () => app(ResendNotification::class)->forBooking($booking, 'session_reminder', $staff))->toThrow(BookingRejected::class, 'Only admins');
});

it('encodes only the check-in URL in the QR code', function (): void {
    $booking = gateBooking();

    $decoded = (new QRCode)->readFromBlob(TicketQr::png($booking))->data;

    expect($decoded)->toBe(route('checkin.show', $booking->ticket_code))
        ->and($decoded)->not->toContain($booking->rider->name);
});

it('shows the QR code on the printable account ticket page', function (): void {
    $booking = gateBooking('2026-11-20 08:00');

    $this->actingAs($booking->user)
        ->get(route('account.tickets.show', $booking->ticket_code))
        ->assertOk()
        ->assertSee('<svg', false)
        ->assertSee('QR code for ticket '.$booking->ticket_code)
        ->assertSee('print');
});
