<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Lead pipeline (PLAN §5, milestone 9): new → contacted → qualified →
 * booked, or lost (with a reason). Open statuses move freely between
 * each other; lost can be reopened; booked is final.
 */
enum LeadStatus: string implements HasColor, HasIcon, HasLabel
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Booked = 'booked';
    case Lost = 'lost';

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

    public function getIcon(): string
    {
        return $this->icon();
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Qualified], true);
    }

    public function canTransitionTo(self $to): bool
    {
        if ($to === $this) {
            return false;
        }

        return match ($this) {
            self::New, self::Contacted, self::Qualified => true,
            self::Lost => $to->isOpen() || $to === self::Booked,
            self::Booked => false,
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::Lost;
    }

    /** Filament badge colour (always shown with the label and an icon). */
    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Contacted => 'warning',
            self::Qualified => 'primary',
            self::Booked => 'success',
            self::Lost => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::New => 'heroicon-o-sparkles',
            self::Contacted => 'heroicon-o-chat-bubble-left-right',
            self::Qualified => 'heroicon-o-check-badge',
            self::Booked => 'heroicon-o-ticket',
            self::Lost => 'heroicon-o-x-circle',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
