<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    /** Fully covered by gift voucher and/or account credit, nothing to charge (milestone 10e Part B). */
    case Discounts = 'discounts';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card (Stripe)',
            self::BankTransfer => 'Bank transfer',
            self::Discounts => 'Gift voucher / account credit',
        };
    }
}
