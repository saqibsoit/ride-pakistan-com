<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Orders\GenerateOrderReference;
use App\Models\Booking;
use RuntimeException;

/**
 * Unique ticket codes: 12 characters from the unambiguous alphabet used
 * for order references (no 0/O, 1/I/L). Unguessable: the code is what the
 * check-in QR (milestone 8) carries.
 */
final class GenerateTicketCode
{
    public const LENGTH = 12;

    private const MAX_ATTEMPTS = 10;

    public function handle(): string
    {
        $alphabet = GenerateOrderReference::ALPHABET;
        $max = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }

            if (! Booking::query()->where('ticket_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique ticket code.');
    }
}
