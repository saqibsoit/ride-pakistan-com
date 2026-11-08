<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Review;
use App\Models\Rider;
use App\Models\RiderSkill;
use App\Models\Skill;
use App\Models\Slot;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\ReviewLink;
use Carbon\CarbonImmutable;

/*
 * Browser smoke tests for milestone 10d on a phone: calendar links on the
 * bookings page, joining a waitlist, the progress page, the review form and
 * a trainer ticking skills. No JavaScript errors or console output.
 */

function browserSlot(int $daysAhead, int $hour = 8, array $attributes = []): Slot
{
    return Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->addDays($daysAhead)->setTime($hour, 0))->create($attributes);
}

it('shows calendar links on upcoming bookings', function (): void {
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create(['name' => 'Ali Raza']);
    $pack = CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(4)]);
    $booking = Booking::factory()->create(['slot_id' => browserSlot(4)->id, 'rider_id' => $rider->id, 'credit_pack_id' => $pack->id]);

    $this->actingAs($user);

    visit(route('account.bookings'))->on()->mobile()
        ->assertSee('Add to calendar')
        ->assertSee('Apple / Outlook (.ics)')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($booking->exists)->toBeTrue();
});

it('lets a customer join the waitlist for a full session', function (): void {
    $slot = browserSlot(5, 8, ['capacity' => 1]);
    Booking::factory()->create(['slot_id' => $slot->id]);
    $user = User::factory()->create();

    $this->actingAs($user);

    visit(route('account.waitlist.create', $slot))->on()->mobile()
        ->assertSee('Join the waitlist')
        ->assertNoJavaScriptErrors()
        ->click('form[action*="waitlist/join"] button[type=submit]')
        ->assertSee('You’re on the waitlist')
        ->assertSee('number 1 in line')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(WaitlistEntry::query()->where('user_id', $user->id)->value('status'))->toBe(WaitlistStatus::Waiting);
});

it('shows a rider’s progress and the next step', function (): void {
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create(['name' => 'Maryam Ali']);
    RiderSkill::query()->create(['rider_id' => $rider->id, 'skill_id' => Skill::query()->where('key', 'grooming')->value('id'), 'achieved_at' => now()]);

    $this->actingAs($user);

    visit(route('account.progress'))->on()->mobile()
        ->assertSee('Maryam Ali')
        ->assertSee('1 of 8 skills')
        ->assertSee('Grooming')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

it('takes a review from the signed link', function (): void {
    $slot = browserSlot(-2);
    $booking = Booking::factory()->create(['slot_id' => $slot->id, 'status' => BookingStatus::Attended]);

    visit(ReviewLink::for($booking))->on()->mobile()
        ->assertSee('How was the ride?')
        ->click('5 stars')
        ->fill('body', 'Patient trainer and calm horses. My son loved it.')
        ->press('Send my review')
        ->assertSee('Thank you.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(Review::query()->sole()->status)->toBe(ReviewStatus::Pending);
});

it('lets a trainer tick skills on the run sheet', function (): void {
    $trainer = User::factory()->trainer()->withMfa()->create();
    $slot = Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->subHour()->startOfHour())->create(['trainer_id' => $trainer->id]);
    $rider = Rider::factory()->create(['name' => 'Zain Malik']);
    $booking = Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'status' => BookingStatus::Attended]);

    $this->actingAs($trainer);

    visit(route('team.sessions.show', $slot))->on()->mobile()
        ->assertSee('Skills Zain Malik can do')
        ->check('Tacking up')
        ->fill('#progress-'.$booking->id, 'Tacked up on his own today.')
        ->press('Save progress')
        ->assertSee('Progress saved for Zain Malik')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(RiderSkill::query()->where('rider_id', $rider->id)->count())->toBe(1)
        ->and($booking->refresh()->progress_note)->toBe('Tacked up on his own today.');
});
