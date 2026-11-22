<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * due: still to pay. paid: received (online or recorded by an admin).
 * refunded: paid and given back (cancellation before the cutoff, or we
 * cancelled). void: no longer owed (the booking was cancelled unpaid).
 */
enum BalanceStatus: string implements HasColor, HasLabel
{
    case Due = 'due';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Due => 'Balance due',
            self::Paid => 'Balance paid',
            self::Refunded => 'Balance refunded',
            self::Void => 'No balance owed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Due => 'warning',
            self::Paid => 'success',
            self::Refunded, self::Void => 'gray',
        };
    }
}
