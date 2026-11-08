<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Actions\Orders\GenerateOrderReference;
use App\Models\VoucherOrder;
use RuntimeException;

/**
 * Human reference for a voucher purchase: "GV-" plus 6 characters from the
 * order-reference alphabet (no look-alikes). The customer types it as the
 * bank transfer reference, like an order's "RP-" reference.
 */
final class GenerateVoucherReference
{
    private const MAX_ATTEMPTS = 10;

    /**
     * @param  (callable(): string)|null  $candidate  test seam
     */
    public function handle(?callable $candidate = null): string
    {
        $candidate ??= self::random(...);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $reference = $candidate();

            if (! VoucherOrder::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('Could not generate a unique voucher reference.');
    }

    public static function random(): string
    {
        $alphabet = GenerateOrderReference::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < GenerateOrderReference::LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return VoucherOrder::PREFIX.$code;
    }

    public static function isValid(string $reference): bool
    {
        return preg_match('/\A'.preg_quote(VoucherOrder::PREFIX, '/').'['.GenerateOrderReference::ALPHABET.']{'.GenerateOrderReference::LENGTH.'}\z/', $reference) === 1;
    }
}
