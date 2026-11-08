<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Why a horse can't work on a date (milestone 10b). */
enum UnavailabilityReason: string implements HasLabel
{
    case Rest = 'rest';
    case Vet = 'vet';
    case Other = 'other';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Rest => 'Rest day',
            self::Vet => 'Vet day',
            self::Other => 'Other',
        };
    }
}
