<?php

declare(strict_types=1);

use App\Actions\Horses\AssignHorse;
use App\Enums\BookingStatus;
use App\Enums\ExperienceLevel;
use App\Enums\HorseLevel;
use App\Enums\UnavailabilityReason;
use App\Exceptions\HorseAssignmentRejected;
use App\Models\Booking;
use App\Models\Horse;
use App\Models\HorseUnavailability;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

/** A confirmed booking in a session at $local (Pakistan time). */
function horseBooking(string $local, array $attributes = [], ?Rider $rider = null): Booking
{
    $slot = Slot::query()->where('starts_at', CarbonImmutable::parse($local, 'Asia/Karachi')->utc())->first()
        ?? Slot::factory()->at(CarbonImmutable::parse($local, 'Asia/Karachi'))->create();

    return Booking::factory()->create(['slot_id' => $slot->id, 'status' => BookingStatus::Confirmed, ...($rider ? ['rider_id' => $rider->id] : []), ...$attributes]);
}

function assignHorse(Booking $booking, ?Horse $horse, ?User $actor = null)
{
    return app(AssignHorse::class)->handle($booking, $horse, $actor ?? User::factory()->staff()->create());
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Karachi'));
});

it('assigns a horse, logs it and shows no warning when the level fits', function (): void {
    $booking = horseBooking('2026-11-14 08:00');
    $horse = Horse::factory()->create(['name' => 'Toofan']);
    $staff = User::factory()->staff()->create();

    $result = assignHorse($booking, $horse, $staff);

    expect($result->changed)->toBeTrue()
        ->and($result->warnings)->toBe([])
        ->and($booking->refresh()->horse_id)->toBe($horse->id);

    $log = Activity::query()->where('event', 'horse_assigned')->sole();
    expect($log->causer_id)->toBe($staff->id)
        ->and($log->properties['to'])->toBe($horse->id);
});

it('blocks a horse that has reached its sessions for the Pakistan day', function (): void {
    $horse = Horse::factory()->create(['name' => 'Badal', 'max_sessions_per_day' => 2]);
    assignHorse(horseBooking('2026-11-14 08:00'), $horse);
    assignHorse(horseBooking('2026-11-14 15:00'), $horse);

    $third = horseBooking('2026-11-14 17:30');

    expect(fn () => assignHorse($third, $horse))
        ->toThrow(HorseAssignmentRejected::class, 'Badal has reached 2 sessions on Sat 14 Nov.');
    expect($third->refresh()->horse_id)->toBeNull();

    // The next day is a new count.
    expect(assignHorse(horseBooking('2026-11-15 08:00'), $horse)->changed)->toBeTrue();
});

it('counts the day in Pakistan time, not UTC', function (): void {
    $horse = Horse::factory()->create(['max_sessions_per_day' => 1]);

    // 04:00 Pakistan on Sunday is 23:00 UTC on Saturday.
    assignHorse(horseBooking('2026-11-15 04:00'), $horse);

    expect(assignHorse(horseBooking('2026-11-14 15:00'), $horse)->changed)->toBeTrue();
});

it('does not count cancelled bookings towards the limit', function (): void {
    $horse = Horse::factory()->create(['max_sessions_per_day' => 1]);
    horseBooking('2026-11-14 08:00', ['horse_id' => $horse->id, 'status' => BookingStatus::Cancelled]);

    expect(assignHorse(horseBooking('2026-11-14 15:00'), $horse)->changed)->toBeTrue();
});

it('blocks the same horse twice in one session or overlapping sessions', function (): void {
    $horse = Horse::factory()->create(['name' => 'Raja', 'max_sessions_per_day' => 4]);
    $first = horseBooking('2026-11-14 08:00');
    assignHorse($first, $horse);

    expect(fn () => assignHorse(horseBooking('2026-11-14 08:00'), $horse))
        ->toThrow(HorseAssignmentRejected::class, 'Raja already carries '.$first->rider->name.' at this time.');

    expect(fn () => assignHorse(horseBooking('2026-11-14 09:00'), $horse))
        ->toThrow(HorseAssignmentRejected::class);
});

it('blocks a horse on its rest or vet day', function (): void {
    $horse = Horse::factory()->create(['name' => 'Sitara']);
    HorseUnavailability::factory()->create(['horse_id' => $horse->id, 'date' => '2026-11-14', 'reason' => UnavailabilityReason::Vet]);

    expect(fn () => assignHorse(horseBooking('2026-11-14 08:00'), $horse))
        ->toThrow(HorseAssignmentRejected::class, 'Sitara has a vet day on Sat 14 Nov.');
    expect(assignHorse(horseBooking('2026-11-15 08:00'), $horse)->changed)->toBeTrue();
});

it('blocks retired and inactive horses', function (): void {
    expect(fn () => assignHorse(horseBooking('2026-11-14 08:00'), Horse::factory()->retired()->create()))
        ->toThrow(HorseAssignmentRejected::class, 'is retired');

    expect(fn () => assignHorse(horseBooking('2026-11-14 08:00'), Horse::factory()->create(['is_active' => false])))
        ->toThrow(HorseAssignmentRejected::class, 'inactive');
});

it('warns but saves when the horse is not listed for the rider level', function (): void {
    $rider = Rider::factory()->create(['name' => 'Ayesha', 'experience_level' => ExperienceLevel::None]);
    $booking = horseBooking('2026-11-14 08:00', rider: $rider);
    $horse = Horse::factory()->levels([HorseLevel::Confident])->create(['name' => 'Shaheen']);

    $result = assignHorse($booking, $horse);

    expect($result->changed)->toBeTrue()
        ->and($booking->refresh()->horse_id)->toBe($horse->id)
        ->and($result->warnings)->toBe(['Shaheen isn\'t listed for beginner riders (Ayesha: never ridden).']);
});

it('warns when the rider experience is not recorded', function (): void {
    $rider = Rider::factory()->create(['experience_level' => null]);

    $result = assignHorse(horseBooking('2026-11-14 08:00', rider: $rider), Horse::factory()->create());

    expect($result->warnings)->toHaveCount(1);
});

it('unassigns, and refuses cancelled bookings', function (): void {
    $horse = Horse::factory()->create();
    $booking = horseBooking('2026-11-14 08:00', ['horse_id' => $horse->id]);

    expect(assignHorse($booking, null)->changed)->toBeTrue()
        ->and($booking->refresh()->horse_id)->toBeNull();

    $cancelled = horseBooking('2026-11-14 08:00', ['status' => BookingStatus::Cancelled]);
    expect(fn () => assignHorse($cancelled, $horse))->toThrow(AuthorizationException::class);
});

it('changes nothing when the same horse is assigned again', function (): void {
    $horse = Horse::factory()->create();
    $booking = horseBooking('2026-11-14 08:00', ['horse_id' => $horse->id]);

    expect(assignHorse($booking, $horse)->changed)->toBeFalse();
    expect(Activity::query()->where('event', 'horse_assigned')->count())->toBe(0);
});

it('lets only the team and the session trainer assign', function (): void {
    $trainer = User::factory()->trainer()->create();
    $other = User::factory()->trainer()->create();
    $booking = horseBooking('2026-11-14 08:00');
    $booking->slot->forceFill(['trainer_id' => $trainer->id])->save();
    $horse = Horse::factory()->create();

    expect(fn () => assignHorse($booking, $horse, $other))->toThrow(AuthorizationException::class);
    expect(fn () => assignHorse($booking, $horse, $booking->user))->toThrow(AuthorizationException::class);
    expect(assignHorse($booking->fresh(), $horse, $trainer)->changed)->toBeTrue();
});
