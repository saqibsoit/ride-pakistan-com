<?php

declare(strict_types=1);

namespace App\Filament\Resources\Horses\Schemas;

use App\Enums\HorseLevel;
use App\Filament\Support\ContentFields;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class HorseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->required()->maxLength(80),
                TextInput::make('max_sessions_per_day')->label('Most sessions a day')
                    ->required()->integer()->minValue(1)->maxValue(6)->default(2)
                    ->helperText('Assigning beyond this is blocked. Cancelled bookings don\'t count.'),
                CheckboxList::make('suitable_levels')->label('Suits riders who are')
                    ->options(HorseLevel::class)
                    ->required()
                    ->columns(3)
                    ->helperText('A rider outside these levels gets a warning on the run sheet, not a block. Beginner = never ridden, intermediate = ridden a few times.'),
                Toggle::make('is_active')->label('Working')->default(true)
                    ->helperText('Switch off for a while (injury, new arrival). Retire a horse that won\'t work again.'),
                Textarea::make('temperament_notes')->label('Temperament')->rows(3)->maxLength(2000)->columnSpanFull()
                    ->helperText('Shown to trainers on the run sheet when they pick a horse.'),
                ContentFields::image('photo', 'Photo')->columnSpanFull(),
            ]);
    }
}
