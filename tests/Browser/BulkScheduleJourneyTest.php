<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Enums\OrderStatus;
use App\Enums\SlotChangeResponse;
use App\Filament\Pages\BulkSchedule;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\SlotChangeNotice;
use App\Models\User;
use App\Support\SlotChangeLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
 * Bulk schedule editor end to end (prompt 15A): an admin moves a booked
 * Saturday session to 07:00 (preview, then apply), and the customer uses
 * the email's link on a phone to move to another session.
 */

beforeEach(function (): void {
    Notification::fake();
});

it('moves a booked session in bulk and lets the customer move from the email link', function (): void {
    $tz = (string) config('app.display_timezone');
    // Inside the page's default range (today + 4 weeks, Saturdays and Sundays).
    $saturday = CarbonImmutable::now($tz)->next(CarbonImmutable::SATURDAY)->addWeek();
    $slot = Slot::factory()->at($saturday->setTime(8, 0))->create();
    $target = Slot::factory()->at($saturday->setTime(15, 0))->create();

    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create(['name' => 'Hira Malik']);
    CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(8)]);
    $booking = app(BookWithCredit::class)->handle($user, $slot->id, [$rider->id])->sole();

    // Admin: preview, then apply.
    test()->actingAs(User::factory()->admin()->withMfa()->create());
    $admin = visit(BulkSchedule::getUrl(panel: 'admin'))->page();
    waitForText($admin, 'Only slots starting at');

    fillOnce($admin, 'Only slots starting at', '08:00');
    fillOnce($admin, 'New start (optional)', '07:00');
    fillOnce($admin, 'New end (optional)', '09:00');
    pressOnce($admin, 'Preview');
    waitForText($admin, 'Preview (nothing is saved yet)');
    waitForText($admin, '1 booked');

    expect($slot->refresh()->localStartsAt()->format('H:i'))->toBe('08:00');

    pressOnce($admin, 'Apply 1 change');
    waitForText($admin, 'Apply these changes?');
    pressOnce($admin, 'Apply');
    waitForText($admin, 'Everything in the preview was applied.');

    expect($slot->refresh()->localStartsAt()->format('H:i'))->toBe('07:00');

    $notice = SlotChangeNotice::query()->where('user_id', $user->id)->sole();

    // Customer, on a phone, signed out: the email's "Move" link.
    auth()->logout();
    $page = visit(SlotChangeLink::show($notice))->on()->mobile()
        ->assertSee('Your session has a new time')
        ->assertSee('Hira Malik');
    $page->assertNoJavaScriptErrors();
    $browser = $page->page();

    pressOnce($browser, 'See other sessions', 'link');
    waitForText($browser, 'Move to another session');
    $browser->locator('input[name="slot_id"][value="'.$target->id.'"]')->check(['timeout' => E2E_WAIT_MS]);
    pressOnce($browser, 'Move my booking');
    waitForText($browser, 'Your booking has moved.');

    expect($booking->refresh()->slot_id)->toBe($target->id)
        ->and($notice->refresh()->status)->toBe(SlotChangeResponse::Moved);
});
