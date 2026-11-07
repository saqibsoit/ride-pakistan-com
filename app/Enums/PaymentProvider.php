<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::BankTransfer => 'Bank transfer',
        };
    }
}
