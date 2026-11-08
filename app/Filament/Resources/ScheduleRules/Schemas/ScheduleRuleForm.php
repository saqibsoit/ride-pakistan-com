<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduleRules\Schemas;

use App\Actions\Slots\PreviewScheduleRule;
use App\Enums\SettingKey;
use App\Filament\Support\TrainerOptions;
use App\Models\ScheduleRule;
use App\Support\Settings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ScheduleRuleForm
{
    public const DAYS = [6 => 'Saturday', 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('day_of_week')->label('Day')->options(self::DAYS)->required()->live(),
                TextInput::make('capacity')->label('Riders')->required()->integer()->minValue(1)->maxValue(50)->live(onBlur: true)
                    ->default(fn (): ?int => app(Settings::class)->int(SettingKey::RidersPerSlot)),
                TimePicker::make('start_time')->label('Starts (Pakistan time)')->required()->seconds(false)->live(onBlur: true)
                    ->helperText('Changes apply to future open slots with no bookings or holds. Booked, closed and cancelled slots stay as they are.'),
                TimePicker::make('end_time')->label('Ends (Pakistan time)')->required()->seconds(false)->after('start_time')->live(onBlur: true),
                DatePicker::make('valid_from')->label('From date')->live()
                    ->helperText('Optional. For seasonal times: e.g. summer times from 1 April, with the winter rule ending 31 March.'),
                DatePicker::make('valid_to')->label('Until date')->afterOrEqual('valid_from')->live(),
                Toggle::make('is_active')->label('Active')->default(true)->live(),
                Select::make('trainer_id')->label('Trainer')
                    ->options(fn (): array => TrainerOptions::all())
                    ->placeholder('Nobody yet')
                    ->helperText('Runs the sessions this rule creates and sees them on the run sheet. Changing it updates future sessions that had the previous trainer; sessions given another trainer keep theirs.'),

                Section::make('Preview')
                    ->description('What saving this rule will do to the bookable slots. Nothing changes until you save.')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('slot_preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get, ?ScheduleRule $record): Htmlable => self::preview($get, $record)),
                    ]),
            ]);
    }

    private static function preview(Get $get, ?ScheduleRule $record): Htmlable
    {
        $preview = app(PreviewScheduleRule::class)->handle([
            'day_of_week' => $get('day_of_week'),
            'start_time' => $get('start_time'),
            'end_time' => $get('end_time'),
            'capacity' => $get('capacity'),
            'valid_from' => $get('valid_from'),
            'valid_to' => $get('valid_to'),
            'is_active' => (bool) $get('is_active'),
        ], $record);

        return new HtmlString(view('filament.schedule-rules.preview', ['preview' => $preview])->render());
    }
}
