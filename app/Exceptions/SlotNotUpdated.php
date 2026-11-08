<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** A manual slot change that would break bookings already made. */
final class SlotNotUpdated extends DomainException
{
    public static function notManual(): self
    {
        return new self('Only manual slots can be edited. Change rule slots through their schedule rule.');
    }

    public static function notOpen(): self
    {
        return new self('Only open slots can be edited. Reopen it first.');
    }

    public static function startedOrPast(): self
    {
        return new self('This slot has already started.');
    }

    public static function inUse(string $what): self
    {
        return new self(sprintf('The %s can’t change: the slot has bookings, holds or unpaid orders. Cancel the slot instead, or add a new one.', $what));
    }

    public static function belowTaken(int $taken): self
    {
        return new self(sprintf('%d seats are already taken, so the capacity can’t go below that.', $taken));
    }
}
