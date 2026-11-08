<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** A manual slot that cannot be added as asked. */
final class SlotNotCreated extends DomainException
{
    public static function notInFuture(): self
    {
        return new self('A manual slot must start in the future.');
    }

    public static function endsBeforeStart(): self
    {
        return new self('A slot must end after it starts.');
    }

    public static function invalidCapacity(int $min, int $max): self
    {
        return new self(sprintf('Capacity must be between %d and %d riders.', $min, $max));
    }

    public static function notATrainer(): self
    {
        return new self('Only a trainer account can run a session.');
    }

    public static function startTaken(string $label): self
    {
        return new self(sprintf('A slot already starts at %s.', $label));
    }
}
