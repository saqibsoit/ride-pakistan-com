<?php

declare(strict_types=1);

use App\Support\Money;

it('formats minor units as rupees', function (int $minor, string $expected): void {
    expect(Money::format($minor))->toBe($expected);
})->with([
    [0, 'PKR 0'],
    [100, 'PKR 1'],
    [1000000, 'PKR 10,000'],
    [10000000, 'PKR 100,000'],
    [1000050, 'PKR 10,000.50'],
    [1000005, 'PKR 10,000.05'],
    [-150000, '−PKR 1,500'],
]);

it('accepts another currency code', function (): void {
    expect(Money::format(2500, 'USD'))->toBe('USD 25');
});
