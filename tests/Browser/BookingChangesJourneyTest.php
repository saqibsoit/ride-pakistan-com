<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Waitlist\JoinWaitlist;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Pest\Browser\Playwright\Page;

/*
 * End-to-end booking changes (milestone 13), on a phone: a customer cancels
 * a credit booking before the cutoff (credit back) and after it (credit
 * used), and a cancellation gives a waiting customer an offer they book and
 * pay by card. Every step waits for its own result (see tests/Pest.php).
 */

beforeEach(function (): void {
    Notification::fake();
});

/** A customer with an active 8-credit pack, booked with one credit on $slot. */
function creditBooking(Slot $slot, string $riderName): Booking
{
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create(['name' => $riderName]);
    CreditPack::factory()->create([
        'order_id' => Order::factory()->for($user)->status(OrderStatus::Paid),
        'credits_total' => 8,
        'expires_at' => now()->addWeeks(6),
    ]);

    return app(BookWithCredit::class)->handle($user, $slot->id, [$rider->id])->sole();
}

/** From /account/bookings: open the booking's cancel page and wait for its notice. */
function openCancelPage(Booking $booking, string $notice): Page
{
    test()->actingAs($booking->user);

    $page = visit(route('account.bookings'))->on()->mobile()
        ->assertSee('Rider: '.$booking->rider->name);
    $browser = $page->page();
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    pressOnce($browser, 'Cancel', 'link', exact: false);
    waitForText($browser, $notice);
    $page->assertNoJavaScriptErrors();

    return $browser;
}

it('cancels a credit booking before the cutoff and returns the credit', function (): void {
    $booking = creditBooking(nearSlot(11, 8), 'Zainab Raza'); // 48 h cutoff long ahead
    $pack = $booking->creditPack;
    expect($pack?->creditsRemaining())->toBe(7);

    $browser = openCancelPage($booking, 'Your credit comes back.');

    pressOnce($browser, 'Yes, cancel this session');
    waitForText($browser, 'Cancelled. Your credit is back in your package.');

    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($pack?->refresh()->creditsRemaining())->toBe(8);
});

it('cancels a credit booking after the cutoff and keeps the credit used', function (): void {
    $booking = creditBooking(nearSlot(1, 15), 'Omar Farooq'); // about a day ahead: past the 48 h cutoff
    $pack = $booking->creditPack;

    $browser = openCancelPage($booking, 'Your credit won’t come back.');

    pressOnce($browser, 'Yes, cancel this session');
    waitForText($browser, 'Cancelled. The credit was used because it was past the cutoff.');

    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($pack?->refresh()->creditsRemaining())->toBe(7);
});

it('offers a freed seat to the waitlist, and the customer books and pays for it', function (): void {
    Product::factory()->create(['slug' => 'single-session', 'name' => 'Single session', 'price_minor' => 1_000_000]);
    $slot = nearSlot(12, 8, ['capacity' => 1]);
    $booking = creditBooking($slot, 'Ali Hassan'); // the only seat

    $waiting = User::factory()->create();
    Rider::factory()->for($waiting)->create(['name' => 'Nida Shah']);
    $entry = app(JoinWaitlist::class)->handle($waiting, $slot->id, 1);
    expect($entry->status)->toBe(WaitlistStatus::Waiting);

    // The booked customer cancels in their account: the seat goes to the waitlist.
    $first = openCancelPage($booking, 'Your credit comes back.');
    pressOnce($first, 'Yes, cancel this session');
    waitForText($first, 'Cancelled. Your credit is back in your package.');

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Offered)
        ->and(BookingHold::query()->where('waitlist_entry_id', $entry->id)->value('seats'))->toBe(1);

    // The waiting customer sees the offer and books it.
    $this->actingAs($waiting);
    $page = visit(route('account.waitlist'))->on()->mobile()
        ->assertSee('held for you until');
    $browser = $page->page();
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    pressOnce($browser, 'Book now', 'link', exact: false);
    waitForText($browser, 'Choose your session.');
    $browser->locator('button[wire\\:click="chooseProduct(\'single-session\')"]')->click(['timeout' => E2E_WAIT_MS]);
    waitForText($browser, 'Who’s riding?');

    $browser->getByLabel('Nida Shah')->check();
    pressOnce($browser, 'Continue');
    waitForText($browser, 'Check and confirm.');
    pressOnce($browser, 'Continue to payment');
    waitForText($browser, 'How would you like to pay?');

    $order = Order::query()->where('user_id', $waiting->id)->latest('id')->firstOrFail();
    payByCardInBrowser($this, $browser, $order);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Booked)
        ->and(Booking::query()->where('order_id', $order->id)->sole()->slot_id)->toBe($slot->id)
        ->and(BookingHold::query()->where('slot_id', $slot->id)->exists())->toBeFalse()
        ->and($slot->refresh()->seatsFree())->toBe(0);

    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();
});
