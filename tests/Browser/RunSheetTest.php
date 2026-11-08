<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Horse;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Browser smoke test of the run sheet (milestone 10b): a trainer on a phone
 * opens their session, puts a rider on a horse and checks them in, with
 * no JavaScript errors or console errors on the page.
 */
it('lets a trainer work the run sheet on a phone', function (): void {
    $start = CarbonImmutable::now('Asia/Karachi')->addHour()->startOfHour();
    $trainer = User::factory()->trainer()->withMfa()->create();
    $slot = Slot::factory()->at($start)->create(['trainer_id' => $trainer->id]);
    $rider = Rider::factory()->create(['name' => 'Zara Khan', 'emergency_contact_phone' => '+923001112233']);
    $booking = Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'status' => BookingStatus::Confirmed]);
    $horse = Horse::factory()->create(['name' => 'Toofan']);

    $this->actingAs($trainer);

    $page = visit(route('team.sessions.show', $slot))->on()->mobile();

    $page->assertSee('Zara Khan')
        ->assertSee('Call +923001112233')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->select('#horse-'.$booking->id, (string) $horse->id)
        ->press('Save horse')
        ->assertSee('Zara Khan is on Toofan')
        ->press('Check in Zara Khan')
        ->assertSee('Checked in: Zara Khan')
        ->assertNoJavaScriptErrors();

    expect($booking->refresh()->status)->toBe(BookingStatus::Attended)
        ->and($booking->horse?->name)->toBe('Toofan');
});
