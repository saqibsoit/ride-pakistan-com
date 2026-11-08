<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A code or credit can't be applied to an order at checkout (milestone 10e Part B). The message is shown to the customer. */
final class DiscountRejected extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    public static function locked(): self
    {
        return new self('Discounts can’t be changed any more for this order.');
    }

    public static function unknownCode(): self
    {
        return new self('We don’t recognise that code. Check it and try again.');
    }
}
