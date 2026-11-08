<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\HorseLevel;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Horse;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/** A slot on Sat 14 Nov 2026 08:00 Pakistan time, run by $trainer. */
function runSheetSlot(?User $trainer = null): Slot
{
    return Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create(['trainer_id' => $trainer?->id]);
}

function runSheetBooking(Slot $slot, array $attributes = []): Booking
{
    return Booking::factory()->create(['slot_id' => $slot->id, 'status' => BookingStatus::Confirmed, ...$attributes]);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 07:00', 'Asia/Karachi'));
});

it('sends guests to the admin login and refuses customers', function (): void {
    $slot = runSheetSlot();
    $booking = runSheetBooking($slot);

    $this->get(route('team.sessions.show', $slot))->assertRedirect('/test-admin/login');
    $this->actingAs($booking->user)->get(route('team.sessions.show', $slot))->assertForbidden();
    $this->actingAs($booking->user)->get(route('team.sessions.index'))->assertForbidden();
});

it('shows a trainer only their own sessions', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $mine = runSheetSlot($trainer);
    $theirs = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 15:00', 'Asia/Karachi'))->create(['trainer_id' => User::factory()->trainer()->create()->id]);

    $this->actingAs($trainer)->get(route('team.sessions.index'))
        ->assertOk()
        ->assertSee('My sessions')
        ->assertSee(route('team.sessions.show', $mine))
        ->assertDontSee(route('team.sessions.show', $theirs));

    $this->actingAs($trainer)->get(route('team.sessions.show', $mine))->assertOk();
    $this->actingAs($trainer)->get(route('team.sessions.show', $theirs))->assertForbidden();
});

it('lists every rider with level, notes, tap-to-call contact, horse and payment status', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $slot = runSheetSlot();
    $rider = Rider::factory()->create([
        'name' => 'Hamza Ali',
        'notes' => 'Nervous around big horses',
        'emergency_contact_name' => 'Sana Ali',
        'emergency_contact_phone' => '+923001112233',
    ]);
    $horse = Horse::factory()->create(['name' => 'Toofan']);
    $paid = Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $rider->user_id, 'amount_minor' => 1_000_000]);
    runSheetBooking($slot, ['rider_id' => $rider->id, 'order_id' => $paid->id, 'horse_id' => $horse->id]);

    $pack = CreditPack::factory()->create();
    runSheetBooking($slot, ['credit_pack_id' => $pack->id, 'user_id' => $pack->user_id, 'rider_id' => Rider::factory()->create(['user_id' => $pack->user_id])->id]);

    $flagged = Order::factory()->status(OrderStatus::Paid)->create(['meta' => ['needs_review' => 'Slot cancelled']]);
    runSheetBooking($slot, ['order_id' => $flagged->id]);

    $this->actingAs($staff)->get(route('team.sessions.show', $slot))
        ->assertOk()
        ->assertSee('Hamza Ali')
        ->assertSee('Nervous around big horses')
        ->assertSee('href="tel:+923001112233"', false)
        ->assertSee('Toofan')
        ->assertSee('Paid')
        ->assertSee('Credit')
        ->assertSee('Needs review')
        ->assertDontSee('PKR')
        ->assertDontSee('10,000')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('noindex', false);
});

it('checks a rider in and refuses outside the window', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $slot = runSheetSlot($trainer);
    $booking = runSheetBooking($slot);

    $this->actingAs($trainer)
        ->post(route('team.sessions.check-in', [$slot, $booking]), ['status' => 'attended'])
        ->assertRedirect(route('team.sessions.show', $slot).'#rider-'.$booking->id);

    expect($booking->refresh()->status)->toBe(BookingStatus::Attended);

    // No-show only once the session has started.
    $late = runSheetBooking($slot);
    $this->actingAs($trainer)->post(route('team.sessions.check-in', [$slot, $late]), ['status' => 'no_show']);
    expect($late->refresh()->status)->toBe(BookingStatus::Confirmed);
    expect(session('runsheet.variant'))->toBe('error');
});

it('lets a trainer check in from the ticket QR only for their own sessions', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $mine = runSheetBooking(runSheetSlot($trainer));
    $other = runSheetBooking(Slot::factory()->at(CarbonImmutable::parse('2026-11-14 15:00', 'Asia/Karachi'))->create());

    $this->actingAs($trainer)->get(route('checkin.show', $mine->ticket_code))->assertOk();
    $this->actingAs($trainer)->get(route('checkin.show', $other->ticket_code))->assertForbidden();
    $this->actingAs($trainer)->post(route('checkin.store', $other->ticket_code), ['status' => 'attended'])->assertForbidden();
});

it('assigns a horse and shows the level warning', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $slot = runSheetSlot($trainer);
    $booking = runSheetBooking($slot);
    $horse = Horse::factory()->levels([HorseLevel::Confident])->create(['name' => 'Shaheen']);

    $this->actingAs($trainer)->post(route('team.sessions.horse', [$slot, $booking]), ['horse_id' => $horse->id])->assertRedirect();

    expect($booking->refresh()->horse_id)->toBe($horse->id)
        ->and(session('runsheet.variant'))->toBe('warning');

    $this->actingAs($trainer)->get(route('team.sessions.show', $slot))->assertSee('isn&#039;t listed for beginner riders', false);
});

it('blocks a horse over its daily limit from the run sheet', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $slot = runSheetSlot();
    $horse = Horse::factory()->create(['name' => 'Badal', 'max_sessions_per_day' => 1]);
    runSheetBooking(Slot::factory()->at(CarbonImmutable::parse('2026-11-14 15:00', 'Asia/Karachi'))->create(), ['horse_id' => $horse->id]);
    $booking = runSheetBooking($slot);

    $this->actingAs($staff)->post(route('team.sessions.horse', [$slot, $booking]), ['horse_id' => $horse->id]);

    expect($booking->refresh()->horse_id)->toBeNull()
        ->and(session('runsheet.message'))->toContain('Badal has reached 1 session');
});

it('refuses a booking from another slot in the URL', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $slot = runSheetSlot();
    $elsewhere = runSheetBooking(Slot::factory()->at(CarbonImmutable::parse('2026-11-15 08:00', 'Asia/Karachi'))->create());

    $this->actingAs($staff)->post(route('team.sessions.note', [$slot, $elsewhere]), ['session_note' => 'x'])->assertNotFound();
});

it('saves an encrypted session note, logged without the text, and shows it in the rider history', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $slot = runSheetSlot($trainer);
    $booking = runSheetBooking($slot);

    $this->actingAs($trainer)->post(route('team.sessions.note', [$slot, $booking]), ['session_note' => 'Rising trot coming along'])->assertRedirect();

    expect($booking->refresh()->session_note)->toBe('Rising trot coming along');
    $raw = DB::table('bookings')->where('id', $booking->id)->value('session_note');
    expect($raw)->not->toContain('Rising trot');

    $log = Activity::query()->where('event', 'session_note_written')->sole();
    expect(json_encode($log->properties))->not->toContain('Rising trot');

    $this->actingAs($trainer)->get(route('team.riders.show', $booking->rider))
        ->assertOk()
        ->assertSee('Rising trot coming along');
});

it('limits the rider history to the team and the rider trainers', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $stranger = User::factory()->trainer()->withMfa()->create();
    $booking = runSheetBooking(runSheetSlot($trainer));

    $this->actingAs($stranger)->get(route('team.riders.show', $booking->rider))->assertForbidden();
    $this->actingAs($trainer)->get(route('team.riders.show', $booking->rider))->assertOk();
    $this->actingAs(User::factory()->staff()->withMfa()->create())->get(route('team.riders.show', $booking->rider))->assertOk();
});

it('has a printable version', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $slot = runSheetSlot();
    $booking = runSheetBooking($slot);

    $this->actingAs($staff)->get(route('team.sessions.show', [$slot, 'print' => 1]))
        ->assertOk()
        ->assertSee('<table', false)
        ->assertSee($booking->rider->name)
        ->assertDontSee('Save horse');
});
