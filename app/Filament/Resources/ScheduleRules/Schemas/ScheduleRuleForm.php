<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduleRules\Schemas;

use App\Enums\SettingKey;
use App\Support\Settings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ScheduleRuleForm
{
    public const DAYS = [6 => 'Saturday', 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('day_of_week')->label('Day')->options(self::DAYS)->required(),
                TextInput::make('capacity')->label('Riders')->required()->integer()->minValue(1)->maxValue(50)
                    ->default(fn (): ?int => app(Settings::class)->int(SettingKey::RidersPerSlot)),
                TimePicker::make('start_time')->label('Starts (Pakistan time)')->required()->seconds(false)
                    ->helperText('Changes apply to future open slots with no bookings or holds. Booked, closed and cancelled slots stay as they are.'),
                TimePicker::make('end_time')->label('Ends (Pakistan time)')->required()->seconds(false)->after('start_time'),
                DatePicker::make('valid_from')->label('From date')->helperText('Optional: for seasonal times.'),
                DatePicker::make('valid_to')->label('Until date')->afterOrEqual('valid_from'),
                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }
}
