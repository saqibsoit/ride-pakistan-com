<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * An issued gift voucher (milestone 10e). Active vouchers have a balance
 * left and have not expired. Changes go through App\Actions\Vouchers\VoucherBalance.
 */
enum VoucherStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Redeemed => 'Fully used',
            self::Expired => 'Expired',
            self::Void => 'Void',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Redeemed => 'info',
            self::Expired, self::Void => 'danger',
        };
    }
}
