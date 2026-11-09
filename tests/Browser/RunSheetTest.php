<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Horse;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Pest\Browser\Playwright\Page;

/*
 * Browser smoke test of the run sheet (milestone 10b): a trainer on a phone
 * opens their session, puts a rider on a horse and checks them in, with
 * no JavaScript errors or console errors on the page.
 *
 * Why it is written this way (it used to fail at random under load):
 * - The page wrapper retries every call with a 1-second step until its
 *   timeout. That's right for assertions, but a retried click submits a
 *   form again. So actions (select, submit) go to Playwright once, and
 *   assertions use the wrapper.
 * - After each submit (a POST and a redirect) the test waits for that
 *   step's own result: the status message in the rider's card. Only the
 *   new page has that message. RUN_SHEET_WAIT_MS is the limit before
 *   giving up, not a delay.
 * - visit()->on()->mobile() opens a new browser page on every call made on
 *   it, so the page is resolved once (the first assertion) and reused.
 * - The slot runs during the current Pakistan hour, so check-in is open at
 *   any time of day. A slot starting "next hour" fell on the next Pakistan
 *   date after 23:00, where check-in is refused.
 */
const RUN_SHEET_WAIT_MS = 30_000;

/**
 * Press a submit button once and wait for the page it leads to: the status
 * message in the rider's card containing $expected.
 */
function submitOnRunSheet(Page $page, string $button, Booking $booking, string $expected): void
{
    $page->getByRole('button', ['name' => $button, 'exact' => true])
        ->click(['timeout' => RUN_SHEET_WAIT_MS]);

    $page->locator('#rider-'.$booking->id.' [role="status"]')
        ->filter(['hasText' => $expected])
        ->waitFor(['state' => 'visible', 'timeout' => RUN_SHEET_WAIT_MS]);
}

it('lets a trainer work the run sheet on a phone', function (): void {
    // In progress now: started at the top of this Pakistan hour, runs two hours.
    $start = CarbonImmutable::now('Asia/Karachi')->startOfHour();
    $trainer = User::factory()->trainer()->withMfa()->create();
    $slot = Slot::factory()->at($start)->create(['trainer_id' => $trainer->id]);
    $rider = Rider::factory()->create(['name' => 'Zara Khan', 'emergency_contact_phone' => '+923001112233']);
    $booking = Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'status' => BookingStatus::Confirmed]);
    $horse = Horse::factory()->create(['name' => 'Toofan']);

    $this->actingAs($trainer);

    // The first call opens the page; keep that one page for the whole test.
    $page = visit(route('team.sessions.show', $slot))->on()->mobile()
        ->assertSee('Zara Khan');
    $playwright = $page->page();

    $page->assertSee('Call +923001112233')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $playwright->locator('#horse-'.$booking->id)->selectOption((string) $horse->id);
    submitOnRunSheet($playwright, 'Save horse', $booking, 'Zara Khan is on Toofan');

    expect($booking->refresh()->horse?->name)->toBe('Toofan');

    submitOnRunSheet($playwright, 'Check in Zara Khan', $booking, 'Checked in: Zara Khan');

    $page->assertSee('Checked in: Zara Khan')
        ->assertDontSee('Already checked in')
        ->assertNoJavaScriptErrors();

    expect($booking->refresh()->status)->toBe(BookingStatus::Attended)
        ->and($booking->horse?->name)->toBe('Toofan');
});

it('lets staff open today’s session from the run sheet list and check a rider in', function (): void {
    $start = CarbonImmutable::now('Asia/Karachi')->startOfHour();
    $staff = User::factory()->staff()->withMfa()->create();
    $slot = Slot::factory()->at($start)->create();
    $rider = Rider::factory()->create(['name' => 'Saad Mirza']);
    $booking = Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'status' => BookingStatus::Confirmed]);

    $this->actingAs($staff);

    // Staff start from the list of today's and the coming sessions.
    $page = visit(route('team.sessions.index'))->on()->mobile()
        ->assertSee('1/'.$slot->capacity);
    $playwright = $page->page();
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    $playwright->locator('a[href="'.route('team.sessions.show', $slot).'"]')->click(['timeout' => RUN_SHEET_WAIT_MS]);
    $playwright->locator('#rider-'.$booking->id)->waitFor(['state' => 'visible', 'timeout' => RUN_SHEET_WAIT_MS]);
    $page->assertSee('Saad Mirza')->assertNoJavaScriptErrors();

    submitOnRunSheet($playwright, 'Check in Saad Mirza', $booking, 'Checked in: Saad Mirza');

    $page->assertSee('Checked in: Saad Mirza')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($booking->refresh()->status)->toBe(BookingStatus::Attended);
});
