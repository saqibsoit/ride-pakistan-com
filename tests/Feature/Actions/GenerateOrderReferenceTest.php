<?php

declare(strict_types=1);

use App\Actions\Orders\GenerateOrderReference;
use App\Models\Order;

it('generates RP- references without look-alike characters', function (): void {
    $action = new GenerateOrderReference;

    for ($i = 0; $i < 200; $i++) {
        $reference = $action->handle();

        expect($reference)->toMatch('/\ARP-[A-Z2-9]{6}\z/')
            ->and(GenerateOrderReference::isValid($reference))->toBeTrue()
            ->and(strpbrk(substr($reference, 3), '01OIL'))->toBeFalse();
    }
});

it('retries until the reference is unused', function (): void {
    Order::factory()->create(['reference' => 'RP-AAAAAA']);
    $candidates = ['RP-AAAAAA', 'RP-BBBBBB'];

    $reference = (new GenerateOrderReference)->handle(function () use (&$candidates): string {
        return (string) array_shift($candidates);
    });

    expect($reference)->toBe('RP-BBBBBB');
});

it('gives up after repeated collisions', function (): void {
    Order::factory()->create(['reference' => 'RP-AAAAAA']);

    (new GenerateOrderReference)->handle(fn (): string => 'RP-AAAAAA');
})->throws(RuntimeException::class);

it('rejects malformed references', function (string $reference): void {
    expect(GenerateOrderReference::isValid($reference))->toBeFalse();
})->with(['RP-ABC12', 'RP-ABCD10', 'rp-abcdef', 'XX-ABCDEF', 'RP-ABCDEFG']);
