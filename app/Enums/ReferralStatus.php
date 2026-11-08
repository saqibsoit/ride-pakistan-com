<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Pending: the new customer signed up through a referral link and hasn't
 * attended a paid session yet. Rewarded: the referrer got their credit.
 * Rejected: an abuse check failed or an admin rejected it (reason kept).
 */
enum ReferralStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Rewarded = 'rewarded';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Rewarded => 'success',
            self::Rejected => 'danger',
        };
    }
}
