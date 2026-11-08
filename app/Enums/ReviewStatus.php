<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A customer review (milestone 10d). New reviews wait for an admin;
 * approved ones appear on the site; hidden ones never do (a reason is
 * required). Approved and hidden can be swapped. Changes go through
 * ChangeReviewStatus.
 */
enum ReviewStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Hidden = 'hidden';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Approved => 'Approved',
            self::Hidden => 'Hidden',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Hidden],
            self::Approved => [self::Hidden],
            self::Hidden => [self::Approved],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function requiresReason(): bool
    {
        return $this === self::Hidden;
    }
}
