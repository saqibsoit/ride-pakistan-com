<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CreditPackStatus: string implements HasLabel
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
