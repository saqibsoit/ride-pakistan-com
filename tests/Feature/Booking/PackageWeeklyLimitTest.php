<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\PackageWeeklyLimit;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Settings\UpdateSettings;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Package credits book at most 2 sessions per rider per Pakistan week
| (Monday to Sunday; decided 2026-11-16). Admins and slot time changes skip it.
*/

beforeEach(function (): void {
    Notification::fake();
    // Monday 16 November 2026, 09:00 Pakistan time.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Karachi'));
    $this->user = User::factory()->create();
    $this->rider = Rider::factory()->for($this->user)->create(['name' => 'Zara Malik']);
    CreditPack::factory()->create([
        'order_id' => Order::factory()->for($this->user)->status(OrderStatus::Paid),
        'credits_total' => 16,
        'credits_used' => 0,
        'expires_at' => now()->addWeeks(10),
    ]);
});

function pwlSlot(string $local): Slot
{
    return Slot::factory()->at(CarbonImmutable::parse($local, 'Asia/Karachi'))->create(['capacity' => 6]);
}

function pwlBook(User $user, Rider $rider, Slot $slot, ?User $actor = null): Booking
{
    return app(BookWithCredit::class)->handle($user, $slot->id, [$rider->id], $actor)->sole();
}

function pwlReason(callable $callback): ?string
{
    try {
        $callback();
    } catch (BookingRejected $e) {
        return $e->reason;
    }

    return null;
}

it('allows two package sessions in a week and refuses the third with a clear message', function (): void {
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-18 08:00'));
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-20 08:00'));

    expect(fn () => pwlBook($this->user, $this->rider, pwlSlot('2026-11-22 08:00')))
        ->toThrow(BookingRejected::class, 'Zara Malik already has 2 package sessions in the week of Mon 16 Nov. Packages allow 2 a week');

    expect(Booking::query()->count())->toBe(2);
});

it('counts the week Monday to Sunday in Pakistan time', function (): void {
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-21 08:00'));
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-22 23:30')); // Sunday night, Pakistan time

    // Monday 00:30 Pakistan time is Sunday 19:30 UTC, but already the next week.
    expect(pwlBook($this->user, $this->rider, pwlSlot('2026-11-23 00:30'))->exists)->toBeTrue();
});

it('counts each rider on their own', function (): void {
    $sister = Rider::factory()->for($this->user)->create();
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-18 08:00'));
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-19 08:00'));

    expect(pwlBook($this->user, $sister, pwlSlot('2026-11-20 08:00'))->exists)->toBeTrue()
        ->and(pwlReason(fn () => app(BookWithCredit::class)->handle($this->user, pwlSlot('2026-11-21 08:00')->id, [$sister->id, $this->rider->id])))
        ->toBe(BookingRejected::WEEKLY_LIMIT);
});

it('ignores cancelled bookings and sessions paid without credits', function (): void {
    $first = pwlBook($this->user, $this->rider, pwlSlot('2026-11-18 08:00'));
    $first->status = BookingStatus::Cancelled;
    $first->save();
    Booking::factory()->create(['slot_id' => pwlSlot('2026-11-19 08:00')->id, 'rider_id' => $this->rider->id, 'user_id' => $this->user->id, 'credit_pack_id' => null]);
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-20 08:00'));

    expect(pwlBook($this->user, $this->rider, pwlSlot('2026-11-21 08:00'))->exists)->toBeTrue();
});

it('lets an admin book past the limit', function (): void {
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-18 08:00'));
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-19 08:00'));

    expect(pwlBook($this->user, $this->rider, pwlSlot('2026-11-20 08:00'), User::factory()->admin()->create())->exists)->toBeTrue();
});

it('follows the setting, and 0 switches the limit off', function (): void {
    app(UpdateSettings::class)->handle([SettingKey::PackageSessionsPerWeek->value => 1]);
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-18 08:00'));
    $thursday = pwlSlot('2026-11-19 08:00');

    expect(fn () => pwlBook($this->user, $this->rider, $thursday))
        ->toThrow(BookingRejected::class, 'already has 1 package session in the week');

    app(UpdateSettings::class)->handle([SettingKey::PackageSessionsPerWeek->value => 0]);

    expect(pwlBook($this->user, $this->rider, $thursday)->exists)->toBeTrue()
        ->and(pwlBook($this->user, $this->rider, pwlSlot('2026-11-20 08:00'))->exists)->toBeTrue()
        ->and(app(PackageWeeklyLimit::class)->limit())->toBe(0);
});

it('defaults to 2 a week', function (): void {
    expect(app(PackageWeeklyLimit::class)->limit())->toBe(2);
});

it('refuses a customer move into a full week but allows moving within the same week', function (): void {
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-25 08:00'));
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-26 08:00'));
    $other = pwlBook($this->user, $this->rider, pwlSlot('2026-11-19 08:00'));

    expect(pwlReason(fn () => app(RescheduleBooking::class)->handle($other, pwlSlot('2026-11-27 08:00')->id, $this->user)))
        ->toBe(BookingRejected::WEEKLY_LIMIT);

    // Within its own week the booking doesn't count against itself.
    $sameWeek = pwlBook($this->user, $this->rider, pwlSlot('2026-11-20 08:00'));
    expect(app(RescheduleBooking::class)->handle($sameWeek, pwlSlot('2026-11-21 08:00')->id, $this->user))->toHaveCount(1);
});

it('lets an admin move a booking into a full week', function (): void {
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-25 08:00'));
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-26 08:00'));
    $other = pwlBook($this->user, $this->rider, pwlSlot('2026-11-19 08:00'));

    expect(app(RescheduleBooking::class)->handle($other, pwlSlot('2026-11-27 08:00')->id, User::factory()->admin()->create(), 'Family request', asAdmin: true))->toHaveCount(1);
});

it('counts a new package first session against the week', function (): void {
    $package = Product::factory()->package(8, 6_000_000, 42)->create();
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-18 08:00'));
    pwlBook($this->user, $this->rider, pwlSlot('2026-11-19 08:00'));

    expect(pwlReason(fn () => app(CreateOrder::class)->handle($this->user, $package, pwlSlot('2026-11-20 08:00')->id, [$this->rider->id])))
        ->toBe(BookingRejected::WEEKLY_LIMIT)
        ->and(app(CreateOrder::class)->handle($this->user, $package, pwlSlot('2026-11-24 08:00')->id, [$this->rider->id])->exists)->toBeTrue();
});
