<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A discount line on an order (order_discounts, milestone 10e). Written
 * at checkout from Part B on; each kind is stored as its own line.
 */
enum DiscountKind: string
{
    case Promo = 'promo';
    case Referral = 'referral';
    case Voucher = 'voucher';
    case AccountCredit = 'account_credit';

    public function label(): string
    {
        return match ($this) {
            self::Promo => 'Promo code',
            self::Referral => 'Referral discount',
            self::Voucher => 'Gift voucher',
            self::AccountCredit => 'Account credit',
        };
    }
}
