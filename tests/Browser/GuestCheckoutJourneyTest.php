<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Product;
use App\Models\Slot;
use App\Support\ManageLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Pest\Browser\Playwright\Page;

/*
 * Guest checkout on a phone (decided 2026-11-12): a signed-out visitor
 * books from /book, gives their details on /book/details and pays by card
 * or bank transfer, then manages the booking from its link. Every step
 * waits for its own result, and after each full page load for Livewire to
 * start (waitForLivewire) before the first wire:click or wire:model (see
 * tests/Pest.php). Helpers are prefixed `gj`.
 */

beforeEach(function (): void {
    Notification::fake();
});

/** From /book to the checkout page as a guest; returns the browser page on checkout. */
function gjBookAsGuest(Slot $slot, string $email): Page
{
    $page = visit(route('book', ['product' => 'single-session']))->on()->mobile()->assertSee('Choose a date.');
    $browser = $page->page();
    $page->assertNoJavaScriptErrors();
    waitForLivewire($browser);

    $browser->locator('button[wire\\:click="chooseSlot('.$slot->id.')"]')->click(['timeout' => E2E_WAIT_MS]);
    waitForText($browser, 'Who’s riding?');
    fillOnce($browser, 'Name', 'Zara Malik');
    pressOnce($browser, 'Continue');
    waitForText($browser, 'Check and confirm.');

    pressOnce($browser, 'Continue as guest');
    waitForText($browser, 'Your details.');
    waitForText($browser, 'Zara Malik');

    // A plain form: no Livewire on this page.
    fillOnce($browser, 'Your name', 'Ayesha Khan');
    fillOnce($browser, 'Email', $email);
    fillOnce($browser, 'Email again', $email);
    fillOnce($browser, 'WhatsApp number', '0334 4554500');
    pressOnce($browser, 'Continue to payment');
    waitForText($browser, 'How would you like to pay?');
    waitForText($browser, 'Booking as a guest');

    return $browser;
}

it('books and pays by card as a guest, then opens the booking', function (): void {
    Product::factory()->create(['slug' => 'single-session', 'name' => 'Single session', 'price_minor' => 1_000_000]);
    $slot = nearSlot(4);

    $browser = gjBookAsGuest($slot, 'guest.card@example.com');

    $order = Order::query()->sole();
    expect($order->placed_as_guest)->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::PendingPayment);

    payByCardInBrowser($this, $browser, $order);

    $booking = Booking::query()->where('order_id', $order->id)->sole();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($booking->status)->toBe(BookingStatus::Confirmed);

    pressOnce($browser, 'Manage this booking', 'link');
    waitForText($browser, $booking->ticket_code);
    waitForText($browser, 'Zara Malik');
});

it('books by bank transfer as a guest and uploads the screenshot from the manage link', function (): void {
    Product::factory()->create(['slug' => 'single-session', 'name' => 'Single session', 'price_minor' => 1_000_000]);
    $slot = nearSlot(5);

    $browser = gjBookAsGuest($slot, 'guest.transfer@example.com');

    pressOnce($browser, 'Pay by bank transfer');
    waitForText($browser, 'Upload your proof');

    $order = Order::query()->sole();
    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingTransfer);

    // The upload goes through the HTTP test client (the browser plugin's
    // server drops multipart bodies), opened from the emailed link like a
    // guest on another device.
    $this->get(ManageLink::forOrder($order))->assertRedirect(route('manage.show', $order->reference));
    $this->post(route('checkout.transfer.upload', $order->reference), [
        'proof' => UploadedFile::fake()->image('transfer.png', 320, 640),
        'transaction_ref' => 'FT2611120042',
    ])->assertRedirect(route('checkout.transfer', $order->reference));

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingVerification);

    $browser->goto(route('manage.show', $order->reference));
    waitForText($browser, 'Your bank transfer is being checked');
});
