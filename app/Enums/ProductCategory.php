<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Catalog grouping for /experiences filter chips. Expeditions live on the
 * separate expeditions site, so they are a chip but not a category here.
 */
enum ProductCategory: string
{
    case Lessons = 'lessons';
    case Private = 'private';
    case Packages = 'packages';

    public function label(): string
    {
        return match ($this) {
            self::Lessons => 'Lessons',
            self::Private => 'Private',
            self::Packages => 'Packages',
        };
    }
}
