<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Full payment at booking, or a deposit now and the balance later (per event). */
enum EventPaymentMode: string implements HasLabel
{
    case Full = 'full';
    case Deposit = 'deposit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Full => 'Full payment at booking',
            self::Deposit => 'Deposit at booking, balance later',
        };
    }
}
