<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case BankTransfer = 'bank_transfer';
    /** An event balance paid in cash at the venue, recorded by an admin. */
    case Cash = 'cash';
    /** An event balance paid by card at the venue, recorded by an admin. */
    case CardTerminal = 'card_terminal';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::BankTransfer => 'Bank transfer',
            self::Cash => 'Cash',
            self::CardTerminal => 'Card at the venue',
        };
    }
}
