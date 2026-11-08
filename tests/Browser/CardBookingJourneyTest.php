<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
 * End-to-end journeys paid by card (milestone 13), on a phone: the customer
 * goes from a public page through /book and checkout to "Pay by card"; the
 * fake Stripe sends them back to the status page, Stripe's signed webhook
 * confirms the payment (FulfilOrder), and the status page's own poll shows
 * the booking. Every step waits for its own result (see tests/Pest.php).
 */

beforeEach(function (): void {
    Notification::fake();
});

/** The checkout page's order, once /book has created it. */
function journeyOrder(User $user): Order
{
    return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
}

it('books and pays a single session by card, from the product page to the ticket', function (): void {
    $product = Product::factory()->create(['slug' => 'single-session', 'name' => 'Single session', 'price_minor' => 1_000_000]);
    $slot = nearSlot(6, 8, ['capacity' => 6]);
    $user = User::factory()->create();
    Rider::factory()->for($user)->create(['name' => 'Ayesha Malik']);

    $this->actingAs($user);

    $page = visit(route('sessions.show', $product))->on()->mobile()
        ->assertSee('Single session');
    $browser = $page->page();
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    // The product page's "Book now" opens /book with the product chosen.
    $browser->locator('a[href*="/book?product=single-session"]:visible')->first()->click(['timeout' => E2E_WAIT_MS]);
    waitForText($browser, 'Choose a date.');

    $browser->locator('button[wire\\:click="chooseSlot('.$slot->id.')"]')->click(['timeout' => E2E_WAIT_MS]);
    waitForText($browser, 'Who’s riding?');

    $browser->getByLabel('Ayesha Malik')->check();
    pressOnce($browser, 'Continue');
    waitForText($browser, 'Check and confirm.');
    $page->assertSee('PKR 10,000')->assertNoJavaScriptErrors();

    pressOnce($browser, 'Continue to payment');
    waitForText($browser, 'How would you like to pay?');

    $order = journeyOrder($user);
    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->amount_minor)->toBe(1_000_000);

    payByCardInBrowser($this, $browser, $order);

    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    $booking = Booking::query()->where('order_id', $order->id)->sole();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->slot_id)->toBe($slot->id);

    // The booking and its ticket are in the account.
    pressOnce($browser, 'Your bookings', 'link');
    waitForText($browser, 'Rider: Ayesha Malik');

    pressOnce($browser, 'View ticket', 'link', exact: false);
    waitForText($browser, $booking->ticket_code);
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();
});

it('books a private session by card and blocks the whole slot', function (): void {
    $private = Product::factory()->private()->create(['slug' => 'private-session', 'name' => 'Private session']);
    $slot = nearSlot(7, 15, ['capacity' => 6]);
    $user = User::factory()->create();
    Rider::factory()->for($user)->create(['name' => 'Bilal Ahmed']);
    Rider::factory()->for($user)->create(['name' => 'Sana Ahmed']);

    $this->actingAs($user);

    $page = visit(route('book', ['product' => 'private-session', 'slot' => $slot->id]))->on()->mobile()
        ->assertSee('Who’s riding?');
    $browser = $page->page();
    $page->assertNoJavaScriptErrors();

    $browser->getByLabel('Bilal Ahmed')->check();
    $browser->getByLabel('Sana Ahmed')->check();
    pressOnce($browser, 'Continue');
    waitForText($browser, 'Check and confirm.');
    $page->assertSee('PKR 30,000')->assertSee('We hold your slot');

    pressOnce($browser, 'Continue to payment');
    waitForText($browser, 'How would you like to pay?');

    $order = journeyOrder($user);
    payByCardInBrowser($this, $browser, $order);

    $bookings = Booking::query()->where('order_id', $order->id)->get();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($bookings)->toHaveCount(2)
        ->and($bookings->every(fn (Booking $b): bool => $b->is_private))->toBeTrue()
        ->and($slot->refresh()->seatsFree())->toBe(0); // two riders, but nobody else can join

    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    expect($private->exists)->toBeTrue();
});

it('buys an 8-class package by card, then books a session with its credits', function (): void {
    Product::factory()->package(8, 6_000_000, 42)->create(['slug' => '8-class-package', 'name' => '8-class package']);
    $slot = nearSlot(8, 8, ['capacity' => 6]);
    $user = User::factory()->create();
    Rider::factory()->for($user)->create(['name' => 'Hamza Qureshi']);

    $this->actingAs($user);

    $page = visit(route('book', ['product' => '8-class-package']))->on()->mobile()
        ->assertSee('Book your first session?');
    $browser = $page->page();
    $page->assertNoJavaScriptErrors();

    // Buy the pack without a date.
    pressOnce($browser, 'I’ll choose my dates later');
    waitForText($browser, 'Check and confirm.');
    $page->assertSee('PKR 60,000')->assertSee('You’ll choose your dates later');

    pressOnce($browser, 'Continue to payment');
    waitForText($browser, 'How would you like to pay?');

    $order = journeyOrder($user);
    payByCardInBrowser($this, $browser, $order);

    $pack = CreditPack::query()->where('order_id', $order->id)->sole();
    expect($pack->credits_total)->toBe(8)
        ->and($pack->status)->toBe(CreditPackStatus::Active)
        ->and(Booking::query()->where('user_id', $user->id)->count())->toBe(0);

    // The credits page shows the pack; book a session with it.
    $browser->goto(route('account.credits'));
    waitForText($browser, '8 of 8 credits left');

    pressOnce($browser, 'Book a session with credits', 'link');
    waitForText($browser, 'Choose a date.');

    $browser->locator('button[wire\\:click="chooseSlot('.$slot->id.')"]')->click(['timeout' => E2E_WAIT_MS]);
    waitForText($browser, 'Who’s riding?');

    $browser->getByLabel('Hamza Qureshi')->check();
    pressOnce($browser, 'Continue');
    waitForText($browser, 'Check and confirm.');
    $page->assertSee('Group session with credits')->assertSee('1 credit');

    pressOnce($browser, 'Book with my credits');
    waitForText($browser, 'Hamza Qureshi');

    $booking = Booking::query()->where('user_id', $user->id)->sole();
    expect($booking->credit_pack_id)->toBe($pack->id)
        ->and($booking->slot_id)->toBe($slot->id)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($pack->refresh()->creditsRemaining())->toBe(7);

    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();
});
