<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** A status change that the state machine does not allow. */
final class InvalidStatusTransition extends DomainException
{
    public static function between(string $subject, string $from, string $to): self
    {
        return new self(sprintf('%s cannot move from "%s" to "%s".', $subject, $from, $to));
    }

    public static function reasonRequired(string $subject, string $to): self
    {
        return new self(sprintf('%s needs a reason to move to "%s".', $subject, $to));
    }
}
