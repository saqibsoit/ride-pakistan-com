<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethod: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card (Stripe)',
            self::BankTransfer => 'Bank transfer',
        };
    }
}
