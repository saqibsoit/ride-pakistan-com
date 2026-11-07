<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a lead is asking about (contact form "interest" field).
 */
enum LeadInterest: string
{
    case Single = 'single';
    case Private = 'private';
    case Package = 'package';
    case Expedition = 'expedition';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'A single session',
            self::Private => 'A private session',
            self::Package => 'A package',
            self::Expedition => 'An expedition',
            self::Other => 'Something else',
        };
    }
}
