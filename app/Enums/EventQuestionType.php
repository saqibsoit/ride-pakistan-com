<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventQuestionType: string implements HasLabel
{
    case Text = 'text';
    case Select = 'select';
    case YesNo = 'yes_no';

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => 'Short text',
            self::Select => 'Choose one',
            self::YesNo => 'Yes / no',
        };
    }
}
