<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Card payment can't start for this order right now. The message is safe
 * to show the customer.
 */
final class CheckoutUnavailable extends RuntimeException
{
    public static function notPayable(): self
    {
        return new self('This order can no longer be paid. Please book again.');
    }

    public static function alreadyPaid(): self
    {
        return new self('We have received your payment and are confirming it.');
    }

    public static function stripeError(): self
    {
        return new self('Card payment is not available right now. Please try again in a minute, or pay by bank transfer.');
    }
}
