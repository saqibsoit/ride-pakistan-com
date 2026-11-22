<?php

declare(strict_types=1);

use App\Support\Events\EventMoney;

/*
| Event money rules (decided 2026-11-22): deposits are a share of the price
| after discounts, rounded up to whole rupees; customer refunds keep the
| non-refundable share and nothing after the cutoff.
*/

it('rounds a deposit share up to whole rupees and never above the price', function (int $price, int $percent, int $share): void {
    expect(EventMoney::share($price, $percent))->toBe($share);
})->with([
    'half of PKR 10,000' => [1_000_000, 50, 500_000],
    'half of PKR 10,001 rounds up' => [1_000_100, 50, 500_100],
    'a third of PKR 10,000' => [1_000_000, 33, 330_000],
    'odd paisa price' => [1_000_050, 50, 500_100],
    '0 %' => [1_000_000, 0, 0],
    '100 %' => [1_000_000, 100, 1_000_000],
    'nothing to share' => [0, 50, 0],
]);

it('splits a price into what is charged now and the balance', function (): void {
    expect(EventMoney::split(2_000_000, 50))->toBe(['now' => 1_000_000, 'balance' => 1_000_000])
        ->and(EventMoney::split(2_000_000, null))->toBe(['now' => 2_000_000, 'balance' => 0])
        ->and(EventMoney::split(150_000, 50))->toBe(['now' => 75_000, 'balance' => 75_000])
        ->and(EventMoney::split(-5, 50))->toBe(['now' => 0, 'balance' => 0]);
});

it('never refunds a deposit on a customer cancellation, and nothing after the cutoff', function (): void {
    // 50 % deposit event: deposit only paid.
    expect(EventMoney::customerRefund(500_000, 1_000_000, 50, true))->toBe(0)
        // Deposit and balance paid: the balance comes back.
        ->and(EventMoney::customerRefund(1_000_000, 1_000_000, 50, true))->toBe(500_000)
        // After the cutoff: nothing, whatever was paid.
        ->and(EventMoney::customerRefund(1_000_000, 1_000_000, 50, false))->toBe(0)
        // Full payment, no non-refundable share: a full refund before the cutoff.
        ->and(EventMoney::customerRefund(1_000_000, 1_000_000, 0, true))->toBe(1_000_000)
        // Full payment with a 20 % share kept.
        ->and(EventMoney::customerRefund(1_000_000, 1_000_000, 20, true))->toBe(800_000);
});
