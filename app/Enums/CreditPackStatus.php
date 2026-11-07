<?php

declare(strict_types=1);

namespace App\Enums;

enum CreditPackStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
