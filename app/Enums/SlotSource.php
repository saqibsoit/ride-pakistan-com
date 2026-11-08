<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * rule: created by GenerateSlots from a schedule rule; the generator keeps
 * it in line with the rule while it is open and unused.
 * manual: added by an admin (CreateManualSlot); the generator never
 * changes or removes it.
 */
enum SlotSource: string implements HasColor, HasLabel
{
    case Rule = 'rule';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Rule => 'Schedule rule',
            self::Manual => 'Manual',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Rule => 'gray',
            self::Manual => 'info',
        };
    }
}
