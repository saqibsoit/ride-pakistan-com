<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a credit ledger entry exists. The delta is signed (+ grant, - use). */
enum CreditReason: string
{
    case Granted = 'granted';
    case Used = 'used';
    case Returned = 'returned';
    case Adjusted = 'adjusted';
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
