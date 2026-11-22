<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventQuestionScope: string implements HasLabel
{
    case Attendee = 'attendee';
    case Booking = 'booking';

    public function getLabel(): string
    {
        return match ($this) {
            self::Attendee => 'Each person',
            self::Booking => 'Once per booking',
        };
    }
}
