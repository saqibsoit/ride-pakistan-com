<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Formats integer minor-unit amounts for display. Money is stored and passed
 * around as integers (paisa); only views call this.
 */
final class Money
{
    /** Minor units per major unit (100 paisa = 1 rupee). */
    private const MINOR_PER_MAJOR = 100;

    /**
     * "PKR 10,000". Paisa are shown only when the amount isn't a whole rupee
     * ("PKR 10,000.50").
     */
    public static function format(int $amountMinor, string $currency = 'PKR'): string
    {
        $negative = $amountMinor < 0;
        $absolute = abs($amountMinor);

        $major = intdiv($absolute, self::MINOR_PER_MAJOR);
        $minor = $absolute % self::MINOR_PER_MAJOR;

        $formatted = number_format($major);

        if ($minor !== 0) {
            $formatted .= '.'.str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
        }

        return ($negative ? '−' : '').$currency.' '.$formatted;
    }
}
