<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * open: bookable. closed: temporarily not bookable (can reopen).
 * cancelled: will not run; terminal, needs a reason (riders are notified
 * from milestone 8). Changes go through App\Actions\Slots\ChangeSlotStatus.
 */
enum SlotStatus: string implements HasColor, HasIcon, HasLabel
{
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function getIcon(): Heroicon
    {
        return $this->icon();
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Closed, self::Cancelled],
            self::Closed => [self::Open, self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function requiresReason(): bool
    {
        return $this === self::Cancelled;
    }

    /** Filament badge colour; status is always shown with an icon and text too. */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Closed => 'warning',
            self::Cancelled => 'danger',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Open => Heroicon::OutlinedCheckCircle,
            self::Closed => Heroicon::OutlinedPauseCircle,
            self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }
}
