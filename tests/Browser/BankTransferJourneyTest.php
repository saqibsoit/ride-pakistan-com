<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Enums\BankTransferStatus;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Filament\Pages\VerifyTransfers;
use App\Models\BankTransfer;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;

/*
 * End-to-end bank transfer journeys (milestone 13). The customer (on a phone)
 * chooses bank transfer at checkout and uploads a screenshot; an admin (on a
 * desktop) approves or rejects it in the Verify transfers queue. Hold expiry:
 * an order with no proof (or an unpaid card hold) runs out and the customer
 * sees it expired. Every step waits for its own result (see tests/Pest.php).
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('transfers');
});

/** A held single-session order for a fresh customer, with the slot it holds. */
function journeyHeldOrder(int $daysAhead, int $hour): Order
{
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create(['name' => 'Fatima Noor']);
    $product = Product::factory()->create(['name' => 'Single session', 'price_minor' => 1_000_000]);
    $slot = nearSlot($daysAhead, $hour, ['capacity' => 6]);

    return app(CreateOrder::class)->handle($user, $product, $slot->id, [$rider->id]);
}

/** Customer side: choose bank transfer on the checkout page and upload the proof. */
function uploadTransferProof(Order $order): Page
{
    test()->actingAs($order->user);

    $page = visit(route('checkout.show', $order->reference))->on()->mobile()
        ->assertSee('How would you like to pay?');
    $browser = $page->page();
    $page->assertNoJavaScriptErrors();

    pressOnce($browser, 'Pay by bank transfer');
    waitForText($browser, 'Upload your proof');
    $page->assertSee($order->reference)->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingTransfer);

    // The upload itself goes through the HTTP test client as the same signed-in
    // customer (same route, validation and private disk): the browser plugin's
    // in-process server doesn't pass multipart bodies on to Laravel yet
    // (pest-plugin-browser 4.3, LaravelHttpServer "@TODO files").
    $page->assertSee('Screenshot or PDF');
    test()->post(route('checkout.transfer.upload', $order->reference), [
        'proof' => UploadedFile::fake()->image('transfer.png', 320, 640),
        'transaction_ref' => 'FT2611090042',
    ])->assertRedirect(route('checkout.transfer', $order->reference));

    $browser->reload();
    waitForText($browser, 'We\'ve received your transfer proof.');
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingVerification);

    return $browser;
}

/** Admin side: open the queue, wait for the order's row and run an action on it. */
function openTransferQueue(Order $order): Page
{
    test()->actingAs(User::factory()->admin()->withMfa()->create());

    // The first panel page of a run is slow (Filament warms up), so wait with
    // the journey limit rather than the wrapper's default.
    $browser = visit(VerifyTransfers::getUrl(panel: 'admin'))->page();
    waitForText($browser, $order->reference);

    return $browser;
}

it('takes a bank transfer from upload to admin approval', function (): void {
    $order = journeyHeldOrder(9, 8);
    uploadTransferProof($order);

    $admin = openTransferQueue($order);
    $row = $admin->locator('tr')->filter(['hasText' => $order->reference]);
    $row->getByRole('button', ['name' => 'Approve'])->click(['timeout' => E2E_WAIT_MS]);

    // Filament's confirmation modal has no dialog role: wait for its text, then
    // press its (only visible) Confirm button.
    waitForText($admin, 'Only approve once PKR 10,000 with reference '.$order->reference);
    pressOnce($admin, 'Confirm');
    waitForText($admin, 'Approved: '.$order->reference.' is confirmed');

    $booking = Booking::query()->where('order_id', $order->id)->sole();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and(BankTransfer::query()->where('order_id', $order->id)->value('status'))->toBe(BankTransferStatus::Approved);

    // The customer's transfer page now says they're booked.
    $this->actingAs($order->user);
    visit(route('checkout.transfer', $order->reference))->on()->mobile()
        ->assertSee('Transfer confirmed. You\'re booked.')
        ->assertNoJavaScriptErrors();
});

it('rejects a bank transfer with a reason and releases the seats', function (): void {
    $order = journeyHeldOrder(9, 15);
    $slot = Slot::query()->findOrFail($order->items()->value('slot_id'));
    uploadTransferProof($order);
    expect($slot->refresh()->seatsFree())->toBe(5);

    $admin = openTransferQueue($order);
    $row = $admin->locator('tr')->filter(['hasText' => $order->reference]);
    $row->getByRole('button', ['name' => 'Reject'])->click(['timeout' => E2E_WAIT_MS]);

    $dialog = $admin->getByRole('dialog');
    $dialog->getByLabel('Reason (sent to the customer)')->fill('The amount on the screenshot is PKR 1,000, not PKR 10,000.', ['timeout' => E2E_WAIT_MS]);
    $dialog->locator('button[type="submit"]')->first()->click(['timeout' => E2E_WAIT_MS]);
    waitForText($admin, 'Rejected: seats released and the customer emailed');

    expect($order->refresh()->status)->toBe(OrderStatus::Rejected)
        ->and(BookingHold::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and($slot->refresh()->seatsFree())->toBe(6)
        ->and(Booking::query()->where('order_id', $order->id)->exists())->toBeFalse();

    $this->actingAs($order->user);
    visit(route('checkout.transfer', $order->reference))->on()->mobile()
        ->assertSee('We couldn\'t confirm this transfer.')
        ->assertSee('The amount on the screenshot is PKR 1,000')
        ->assertNoJavaScriptErrors();
});

it('expires a bank transfer hold with no proof at the deadline', function (): void {
    $order = journeyHeldOrder(10, 8);
    $slot = Slot::query()->findOrFail($order->items()->value('slot_id'));
    $this->actingAs($order->user);

    $page = visit(route('checkout.show', $order->reference))->on()->mobile()
        ->assertSee('How would you like to pay?');
    $browser = $page->page();

    pressOnce($browser, 'Pay by bank transfer');
    waitForText($browser, 'Upload your proof');

    $deadline = $order->refresh()->expires_at;
    expect($order->status)->toBe(OrderStatus::AwaitingTransfer)
        ->and($deadline)->not->toBeNull();

    // A minute past the deadline, the scheduler's holds:release runs.
    $this->travelTo($deadline->addMinute());
    $this->artisan('holds:release')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($slot->refresh()->seatsFree())->toBe(6);

    $browser->reload();
    waitForText($browser, 'This order has expired.');
    $page->assertSee('No transfer proof arrived before the deadline')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

it('expires an unpaid card hold and shows it on the checkout page', function (): void {
    $order = journeyHeldOrder(10, 15);
    $slot = Slot::query()->findOrFail($order->items()->value('slot_id'));
    $this->actingAs($order->user);

    $page = visit(route('checkout.show', $order->reference))->on()->mobile()
        ->assertSee('seats are held');
    $browser = $page->page();

    $this->travelTo($order->expires_at->addMinute());
    $this->artisan('holds:release')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($slot->refresh()->seatsFree())->toBe(6);

    $browser->reload();
    waitForText($browser, 'This order has expired.');
    $page->assertDontSee('How would you like to pay?')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
