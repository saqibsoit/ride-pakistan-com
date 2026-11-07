<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a product sells. Drives booking rules from milestone 5 on
 * (private blocks a whole slot; packages grant credits).
 */
enum ProductType: string
{
    case Single = 'single';
    case Private = 'private';
    case Package = 'package';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single session',
            self::Private => 'Private session',
            self::Package => 'Package',
        };
    }
}
