<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A waitlist entry (milestone 10d). Waiting in line; offered (seats held
 * for them until offer_expires_at); then booked, expired (offer not used,
 * it moved on), cancelled (left, or removed by an admin) or closed (the
 * slot was cancelled, closed or started). Changes go through
 * ChangeWaitlistStatus.
 */
enum WaitlistStatus: string implements HasLabel
{
    case Waiting = 'waiting';
    case Offered = 'offered';
    case Booked = 'booked';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Offered => 'Offer sent',
            self::Booked => 'Booked',
            self::Expired => 'Offer expired',
            self::Cancelled => 'Left the waitlist',
            self::Closed => 'Closed',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Waiting => [self::Offered, self::Booked, self::Cancelled, self::Closed],
            self::Offered => [self::Booked, self::Expired, self::Cancelled, self::Closed],
            default => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isActive(): bool
    {
        return $this === self::Waiting || $this === self::Offered;
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Waiting->value, self::Offered->value];
    }
}
