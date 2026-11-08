<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Enums\OrderStatus;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;

/*
 * Browser smoke test (milestone 10e Part B): on a phone, a customer enters
 * a promo code and a gift voucher at checkout, sees the breakdown, and
 * confirms an order the voucher covers. No JavaScript or console errors.
 */
it('applies a promo code and a gift voucher at checkout, then confirms the covered order', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price_minor' => 1_000_000, 'name' => 'Single session']);
    $slot = Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->addDays(4)->setTime(8, 0))->create(['capacity' => 6]);
    $order = app(CreateOrder::class)->handle($user, $product, $slot->id, [Rider::factory()->for($user)->create()->id]);
    PromoCode::factory()->create(['code' => 'SPRING10', 'percent' => 10]);
    $voucher = Voucher::factory()->create(['initial_minor' => 1_000_000, 'balance_minor' => 1_000_000]);

    $this->actingAs($user);

    $page = visit(route('checkout.show', $order->reference))->on()->mobile();

    $page->assertSee('Gift voucher or promo code')
        ->fill('code', 'spring10')
        ->press('Apply')
        ->assertSee('Promo code SPRING10')
        ->assertSee('PKR 9,000')
        ->fill('code', $voucher->formattedCode())
        ->press('Apply')
        ->assertSee('Nothing left to pay.')
        ->press('Confirm booking')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($voucher->refresh()->balance_minor)->toBe(100_000); // 10,000 − 9,000 used
});
