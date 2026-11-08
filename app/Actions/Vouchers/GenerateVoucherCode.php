<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Actions\Orders\GenerateOrderReference;
use App\Models\Voucher;
use RuntimeException;

/**
 * Unique, unguessable voucher codes: 12 characters from the unambiguous
 * alphabet (31^12, about 7.9 × 10^17), shown as XXXX-XXXX-XXXX.
 */
final class GenerateVoucherCode
{
    public const LENGTH = 12;

    private const MAX_ATTEMPTS = 10;

    /**
     * @param  (callable(): string)|null  $candidate  test seam
     */
    public function handle(?callable $candidate = null): string
    {
        $candidate ??= self::random(...);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = $candidate();

            if (! Voucher::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique voucher code.');
    }

    public static function random(): string
    {
        $alphabet = GenerateOrderReference::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    public static function isValid(string $code): bool
    {
        return preg_match('/\A['.GenerateOrderReference::ALPHABET.']{'.self::LENGTH.'}\z/', $code) === 1;
    }
}
