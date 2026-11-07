<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Placeholder brackets until the minimum rider age and rules for minors
 * are decided (docs/OPEN-QUESTIONS.md 1.5, 3.3).
 */
enum AgeBracket: string
{
    case Under12 = 'under_12';
    case From12To15 = '12_15';
    case From16To17 = '16_17';
    case Adult = '18_plus';

    public function label(): string
    {
        return match ($this) {
            self::Under12 => 'Under 12',
            self::From12To15 => '12 to 15',
            self::From16To17 => '16 to 17',
            self::Adult => '18 or over',
        };
    }

    public function isMinor(): bool
    {
        return $this !== self::Adult;
    }
}
