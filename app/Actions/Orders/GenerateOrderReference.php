<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use RuntimeException;

/**
 * Human order reference (PLAN §3.1): "RP-" plus 6 characters with no
 * look-alikes (no 0/O, 1/I/L). Customers type it as the bank transfer
 * reference, so it must be easy to read aloud and copy.
 */
final class GenerateOrderReference
{
    public const PREFIX = 'RP-';

    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 6;

    private const MAX_ATTEMPTS = 10;

    /**
     * @param  (callable(): string)|null  $candidate  test seam; defaults to a random reference
     */
    public function handle(?callable $candidate = null): string
    {
        $candidate ??= self::random(...);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $reference = $candidate();

            if (! Order::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('Could not generate a unique order reference.');
    }

    public static function random(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return self::PREFIX.$code;
    }

    public static function isValid(string $reference): bool
    {
        return preg_match('/\A'.preg_quote(self::PREFIX, '/').'['.self::ALPHABET.']{'.self::LENGTH.'}\z/', $reference) === 1;
    }
}
