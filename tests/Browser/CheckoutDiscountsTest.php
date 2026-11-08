<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Rider;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\Notification;
use Pest\Browser\Playwright\Page;

/*
 * Browser journeys for discounts at checkout (milestone 10e Part B, made
 * end-to-end in milestone 13), on a phone: a customer enters a promo code and
 * a gift voucher, sees the breakdown, and either confirms an order the
 * voucher covers or pays what is left by card. Actions go to Playwright once
 * and each step waits for its own result (see tests/Pest.php); the old
 * version used the retrying press() and failed now and then under load.
 */

beforeEach(function (): void {
    Notification::fake();
});

/** A held single-session order (PKR 10,000) for a fresh customer, signed in. */
function discountOrder(int $daysAhead): Order
{
    $user = User::factory()->create();
    $product = Product::factory()->create(['price_minor' => 1_000_000, 'name' => 'Single session']);
    $slot = nearSlot($daysAhead, 8, ['capacity' => 6]);
    $order = app(CreateOrder::class)->handle($user, $product, $slot->id, [Rider::factory()->for($user)->create()->id]);

    test()->actingAs($user);

    return $order;
}

/** Type a code into the checkout's single code field and wait for its confirmation. */
function applyCode(Page $browser, string $code, string $applied): void
{
    $browser->locator('[name="code"]')->fill($code, ['timeout' => E2E_WAIT_MS]);
    pressOnce($browser, 'Apply');
    waitForText($browser, $applied);
}

it('applies a promo code and a gift voucher at checkout, then confirms the covered order', function (): void {
    $order = discountOrder(4);
    PromoCode::factory()->create(['code' => 'SPRING10', 'percent' => 10]);
    $voucher = Voucher::factory()->create(['initial_minor' => 1_000_000, 'balance_minor' => 1_000_000]);

    $page = visit(route('checkout.show', $order->reference))->on()->mobile()
        ->assertSee('Gift voucher or promo code');
    $browser = $page->page();

    applyCode($browser, 'spring10', 'Promo code applied.');
    $page->assertSee('Promo code SPRING10')->assertSee('PKR 9,000');

    applyCode($browser, $voucher->formattedCode(), 'Nothing left to pay.');

    pressOnce($browser, 'Confirm booking');
    waitForText($browser, 'Payment received. You\'re booked.');
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($voucher->refresh()->balance_minor)->toBe(100_000); // 10,000 − 9,000 used
});

it('applies a promo code and part of a gift voucher, then pays the rest by card', function (): void {
    $order = discountOrder(5);
    PromoCode::factory()->create(['code' => 'SPRING10', 'percent' => 10]);
    $voucher = Voucher::factory()->create(['initial_minor' => 500_000, 'balance_minor' => 500_000]);

    $page = visit(route('checkout.show', $order->reference))->on()->mobile()
        ->assertSee('Gift voucher or promo code');
    $browser = $page->page();

    applyCode($browser, 'SPRING10', 'Promo code applied.');
    applyCode($browser, $voucher->formattedCode(), 'Gift voucher applied.');
    $page->assertSee('PKR 4,000')->assertSee('How would you like to pay?');

    // PKR 10,000 − 10 % = 9,000; the voucher's 5,000 leaves 4,000 for the card.
    expect($order->refresh()->amount_minor)->toBe(400_000);

    payByCardInBrowser($this, $browser, $order);
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    expect(fakeStripe()->lastParams()['line_items'][0]['price_data']['unit_amount'])->toBe(400_000)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($voucher->refresh()->balance_minor)->toBe(0);
});
