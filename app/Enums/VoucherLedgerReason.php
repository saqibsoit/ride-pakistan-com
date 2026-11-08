<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a voucher balance changed. The delta is signed (+ issue/restore, - use). */
enum VoucherLedgerReason: string
{
    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case Restored = 'restored';
    case Adjusted = 'adjusted';
    case Expired = 'expired';
    case Voided = 'voided';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
