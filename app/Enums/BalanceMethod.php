<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** How an event balance was paid. Card online is the manage page; the rest an admin records. */
enum BalanceMethod: string implements HasLabel
{
    case CardOnline = 'card_online';
    case Cash = 'cash';
    case CardTerminal = 'card_terminal';
    case BankTransfer = 'bank_transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::CardOnline => 'Card online',
            self::Cash => 'Cash',
            self::CardTerminal => 'Card at the venue',
            self::BankTransfer => 'Bank transfer',
        };
    }

    /**
     * What an admin can record by hand.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Cash, self::CardTerminal, self::BankTransfer];
    }

    public function provider(): PaymentProvider
    {
        return match ($this) {
            self::CardOnline => PaymentProvider::Stripe,
            self::Cash => PaymentProvider::Cash,
            self::CardTerminal => PaymentProvider::CardTerminal,
            self::BankTransfer => PaymentProvider::BankTransfer,
        };
    }
}
