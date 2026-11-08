<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Groups quick replies on the copy lists (milestone 10c). */
enum QuickReplyCategory: string implements HasLabel
{
    case Booking = 'booking';
    case Prices = 'prices';
    case Timings = 'timings';
    case Location = 'location';
    case Packages = 'packages';
    case Private = 'private';
    case Payment = 'payment';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private sessions',
            default => ucfirst($this->value),
        };
    }
}
