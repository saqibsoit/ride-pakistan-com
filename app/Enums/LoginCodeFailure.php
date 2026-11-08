<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a submitted sign-in code was not accepted. */
enum LoginCodeFailure: string
{
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Locked = 'locked';

    public function message(int $attemptsLeft = 0): string
    {
        return match ($this) {
            self::Invalid => $attemptsLeft > 0
                ? 'That code isn’t right. You have '.$attemptsLeft.' '.($attemptsLeft === 1 ? 'try' : 'tries').' left.'
                : 'That code isn’t right. Request a new code.',
            self::Expired => 'That code has expired or was already used. Request a new code.',
            self::Locked => 'Too many wrong codes. Request a new code.',
        };
    }
}
