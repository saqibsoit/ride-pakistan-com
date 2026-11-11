<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * A private session request (prompt 15B). Changes only through
 * PrivateRequestStateMachine:
 *
 *   requested → approved | declined | expired | withdrawn
 *   approved  → paid | lapsed (its order expired, was cancelled or rejected)
 */
enum PrivateRequestStatus: string implements HasColor, HasIcon, HasLabel
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Paid = 'paid';
    case Declined = 'declined';
    case Expired = 'expired';
    case Lapsed = 'lapsed';
    case Withdrawn = 'withdrawn';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved, waiting for payment',
            self::Paid => 'Booked',
            self::Declined => 'Declined',
            self::Expired => 'Expired (no answer in time)',
            self::Lapsed => 'Not paid in time',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Paid => 'success',
            default => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Requested => Heroicon::OutlinedClock,
            self::Approved => Heroicon::OutlinedCreditCard,
            self::Paid => Heroicon::OutlinedCheckCircle,
            self::Declined => Heroicon::OutlinedXCircle,
            self::Expired, self::Lapsed => Heroicon::OutlinedExclamationCircle,
            self::Withdrawn => Heroicon::OutlinedArrowUturnLeft,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Declined, self::Expired, self::Withdrawn],
            self::Approved => [self::Paid, self::Lapsed],
            default => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return $this === self::Requested || $this === self::Approved;
    }

    public function requiresReason(): bool
    {
        return $this === self::Declined;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Requested->value, self::Approved->value];
    }
}
