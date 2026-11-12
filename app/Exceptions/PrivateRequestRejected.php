<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A private session request (prompt 15B) can't be sent, approved,
 * declined or withdrawn. The message is safe to show the customer or the
 * admin.
 */
final class PrivateRequestRejected extends DomainException
{
    /** The account already has an open request: never shown to a guest (guest checkout). */
    public bool $alreadyOpen = false;

    public static function because(string $message): self
    {
        return new self($message);
    }

    public static function alreadyOpen(): self
    {
        $e = new self('You already have a private session request open. You can withdraw it from your account first.');
        $e->alreadyOpen = true;

        return $e;
    }

    public static function clash(string $label, int $buffer): self
    {
        return new self(sprintf('That time is too close to another session (%s). Sessions need %d minutes between them.', $label, $buffer));
    }

    public static function notOpen(): self
    {
        return new self('This request has already been answered or has expired.');
    }
}
