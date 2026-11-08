<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** An account action (deletion, restore) is not allowed right now. */
final class AccountActionBlocked extends RuntimeException
{
    /**
     * @param  list<string>  $reasons  shown to the customer
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
