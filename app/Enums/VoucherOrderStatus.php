<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Gift voucher purchase lifecycle (milestone 10e). Mirrors the order
 * payment states (PLAN §3.3) without seats. Transitions are validated and
 * logged only by App\Actions\Vouchers\VoucherOrderStateMachine.
 */
enum VoucherOrderStatus: string implements HasColor, HasLabel
{
    case PendingPayment = 'pending_payment';
    case AwaitingTransfer = 'awaiting_transfer';
    case AwaitingVerification = 'awaiting_verification';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pending payment',
            self::AwaitingTransfer => 'Awaiting transfer',
            self::AwaitingVerification => 'Awaiting verification',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::PendingPayment, self::AwaitingTransfer, self::AwaitingVerification => 'warning',
            self::Refunded => 'info',
            self::Rejected, self::Expired, self::Cancelled => 'danger',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::AwaitingTransfer, self::Expired, self::Cancelled],
            self::AwaitingTransfer => [self::AwaitingVerification, self::Paid, self::Expired, self::Cancelled],
            self::AwaitingVerification => [self::Paid, self::Rejected, self::Cancelled],
            self::Paid => [self::Refunded],
            // A late card payment on an expired purchase still issues the
            // voucher: nothing is scarce, so there is nothing to refund.
            self::Expired => [self::Paid],
            self::Rejected, self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled, self::Refunded], true);
    }

    public function isUnpaid(): bool
    {
        return in_array($this, [self::PendingPayment, self::AwaitingTransfer, self::AwaitingVerification], true);
    }
}
