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
    /**
     * Only an order's snapshot type for an event booking (decided
     * 2026-11-22). Never a product's type: events are RideEvent rows.
     */
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single session',
            self::Private => 'Private session',
            self::Package => 'Package',
            self::Event => 'Event',
        };
    }

    /**
     * Types a product can have.
     *
     * @return list<self>
     */
    public static function forProducts(): array
    {
        return [self::Single, self::Private, self::Package];
    }
}
