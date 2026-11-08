<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which riders a horse suits (milestone 10b). A rider's experience maps
 * onto these: never ridden → beginner, a few times → intermediate,
 * confident → confident.
 */
enum HorseLevel: string implements HasLabel
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Confident = 'confident';

    public static function fromExperience(ExperienceLevel $experience): self
    {
        return match ($experience) {
            ExperienceLevel::None => self::Beginner,
            ExperienceLevel::Some => self::Intermediate,
            ExperienceLevel::Confident => self::Confident,
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
