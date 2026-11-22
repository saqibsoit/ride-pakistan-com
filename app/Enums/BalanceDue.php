<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * When a deposit event's balance is due. On the day: paid at the venue
 * (or online until the start). By a deadline: online until that many
 * hours before the start. Unpaid balances are never cancelled
 * automatically; the run sheet shows them.
 */
enum BalanceDue: string implements HasLabel
{
    case OnTheDay = 'on_the_day';
    case ByDeadline = 'by_deadline';

    public function getLabel(): string
    {
        return match ($this) {
            self::OnTheDay => 'On the day (cash or card at the venue, or online before)',
            self::ByDeadline => 'Online before a deadline',
        };
    }
}
