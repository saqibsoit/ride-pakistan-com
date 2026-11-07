<?php

declare(strict_types=1);

namespace App\Enums;

/** Placeholder levels (docs/OPEN-QUESTIONS.md). No experience is needed to book. */
enum ExperienceLevel: string
{
    case None = 'none';
    case Some = 'some';
    case Confident = 'confident';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Never ridden',
            self::Some => 'Ridden a few times',
            self::Confident => 'Confident rider',
        };
    }
}
