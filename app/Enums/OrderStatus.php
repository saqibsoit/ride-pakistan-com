<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Order lifecycle (PLAN §3.3). Transitions are validated and logged only
 * by App\Actions\Orders\OrderStateMachine.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case AwaitingTransfer = 'awaiting_transfer';
    case AwaitingVerification = 'awaiting_verification';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
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
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::AwaitingTransfer, self::Expired, self::Cancelled],
            self::AwaitingTransfer => [self::AwaitingVerification, self::Expired, self::Cancelled],
            self::AwaitingVerification => [self::Paid, self::Rejected, self::Expired, self::Cancelled],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Rejected, self::Expired, self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Moving into this status must be explained. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled, self::PartiallyRefunded, self::Refunded], true);
    }

    /** Not yet paid and still open (PLAN §3.3 "any unpaid"). */
    public function isUnpaid(): bool
    {
        return in_array($this, [self::PendingPayment, self::AwaitingTransfer, self::AwaitingVerification], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::PendingPayment, self::AwaitingTransfer, self::AwaitingVerification => 'warning',
            self::PartiallyRefunded, self::Refunded => 'info',
            self::Rejected, self::Expired, self::Cancelled => 'danger',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Paid => Heroicon::OutlinedCheckCircle,
            self::PendingPayment, self::AwaitingTransfer, self::AwaitingVerification => Heroicon::OutlinedClock,
            self::PartiallyRefunded, self::Refunded => Heroicon::OutlinedArrowUturnLeft,
            self::Rejected, self::Expired, self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }
}
