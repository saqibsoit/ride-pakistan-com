<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A customer's answer to a slot time change (prompt 15A). Pending until
 * they use one of the email's links; one answer per notice, final.
 * No answer means the bookings stay at the new time. Changes go through
 * RespondToSlotChange.
 */
enum SlotChangeResponse: string implements HasLabel
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Moved = 'moved';
    case Cancelled = 'cancelled';
    case RefundRequested = 'refund_requested';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'No answer yet',
            self::Accepted => 'Kept the new time',
            self::Moved => 'Moved to another session',
            self::Cancelled => 'Cancelled',
            self::RefundRequested => 'Asked for a refund',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return $this === self::Pending
            ? [self::Accepted, self::Moved, self::Cancelled, self::RefundRequested]
            : [];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
