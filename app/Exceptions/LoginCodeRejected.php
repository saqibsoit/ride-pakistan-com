<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\LoginCodeFailure;
use RuntimeException;

final class LoginCodeRejected extends RuntimeException
{
    public function __construct(
        public readonly LoginCodeFailure $failure,
        public readonly int $attemptsLeft = 0,
    ) {
        parent::__construct($failure->message($attemptsLeft));
    }
}
