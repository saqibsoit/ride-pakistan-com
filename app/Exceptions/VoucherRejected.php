<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A gift voucher rule said no (milestone 10e). The message is safe to
 * show the customer or admin.
 */
final class VoucherRejected extends RuntimeException
{
    public static function amountOutOfRange(string $min, string $max): self
    {
        return new self('Choose an amount between '.$min.' and '.$max.'.');
    }

    public static function productNotOnSale(): self
    {
        return new self('That session is not on sale any more. Choose another or enter an amount.');
    }

    public static function sendDate(int $days): self
    {
        return new self('Choose a send date from today up to '.$days.' days ahead.');
    }

    public static function notPayable(): self
    {
        return new self('This voucher purchase can no longer be paid. Please start again.');
    }

    public static function alreadyPaid(): self
    {
        return new self('We have received your payment and are confirming it.');
    }

    public static function transferNotOpen(): self
    {
        return new self('This purchase is not waiting for a bank transfer.');
    }

    public static function alreadyDelivered(): self
    {
        return new self('This voucher has already been sent, so the recipient can no longer be changed.');
    }

    public static function notUsable(string $why): self
    {
        return new self($why);
    }
}
