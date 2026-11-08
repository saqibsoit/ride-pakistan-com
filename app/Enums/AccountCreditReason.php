<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a customer's PKR account credit changed. The delta is signed. */
enum AccountCreditReason: string
{
    case ReferralReward = 'referral_reward';
    case Used = 'used';
    case Returned = 'returned';
    case Adjusted = 'adjusted';

    public function label(): string
    {
        return match ($this) {
            self::ReferralReward => 'Referral reward',
            self::Used => 'Used at checkout',
            self::Returned => 'Returned',
            self::Adjusted => 'Adjusted by an admin',
        };
    }
}
