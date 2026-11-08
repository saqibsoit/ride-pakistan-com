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

    public static function transferTooLate(int $hours): self
    {
        return new self('Bank transfer needs at least '.$hours.' '.($hours === 1 ? 'hour' : 'hours').' before the session starts. Please pay by card.');
    }

    public static function cardPageStillOpen(): self
    {
        return new self('We couldn\'t switch this order to bank transfer just now. Please try again in a minute.');
    }

    public static function transferNotOpen(): self
    {
        return new self('This order is not waiting for a bank transfer.');
    }

    public static function stripeError(): self
    {
        return new self('Card payment is not available right now. Please try again in a minute, or pay by bank transfer.');
    }
}
