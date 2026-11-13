<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A guest (signed out) booking, gift voucher purchase or private request
 * can't go ahead. Every message is the same whatever account the email
 * belongs to, so a guest never learns whether an account exists or what
 * kind it is (guest checkout, decided 2026-11-12).
 */
final class GuestCheckoutRejected extends DomainException
{
    public const EMAIL = 'We can’t take a booking for this email online. Please use another email or contact us.';

    public const TOO_MANY = 'Too many attempts. Please wait a few minutes and try again.';

    public const VERIFICATION = 'Please complete the security check and try again.';

    public static function email(): self
    {
        return new self(self::EMAIL);
    }

    public static function tooMany(): self
    {
        return new self(self::TOO_MANY);
    }

    public static function verification(): self
    {
        return new self(self::VERIFICATION);
    }
}
