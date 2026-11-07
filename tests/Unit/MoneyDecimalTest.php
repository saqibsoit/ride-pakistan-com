<?php

declare(strict_types=1);

use App\Support\Money;

it('formats minor units as a decimal string without floats', function (int $minor, string $expected): void {
    expect(Money::decimal($minor))->toBe($expected);
})->with([
    [1_000_000, '10000.00'],
    [1_000_050, '10000.50'],
    [5, '0.05'],
    [0, '0.00'],
    [-250, '-2.50'],
]);
