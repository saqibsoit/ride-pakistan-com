<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots\Schemas;

use App\Filament\Resources\ScheduleRules\Schemas\ScheduleRuleForm;
use App\Models\Slot;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SlotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Session')
                ->columns(3)
                ->schema([
                    TextEntry::make('when')->label('When (Pakistan time)')
                        ->state(fn (Slot $record): string => $record->label()),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('source')->badge(),
                    IconEntry::make('private_only')->label('Private sessions only')->boolean(),
                    TextEntry::make('rule')->label('From schedule rule')
                        ->visible(fn (Slot $record): bool => ! $record->isManual())
                        ->state(fn (Slot $record): ?string => $record->scheduleRule === null ? null : sprintf(
                            '%s %s–%s',
                            ScheduleRuleForm::DAYS[$record->scheduleRule->day_of_week] ?? '',
                            $record->scheduleRule->startTime(),
                            $record->scheduleRule->endTime(),
                        ))
                        ->placeholder('Rule removed'),
                ]),
            Section::make('Seats')
                ->columns(4)
                ->schema([
                    TextEntry::make('capacity')->label('Capacity'),
                    TextEntry::make('seats_booked')->label('Booked')
                        ->state(fn (Slot $record): int => $record->seatsBooked()),
                    TextEntry::make('seats_held')->label('Held (unpaid)')
                        ->state(fn (Slot $record): int => $record->seatsHeld()),
                    TextEntry::make('seats_free')->label('Free')
                        ->state(fn (Slot $record): int => $record->seatsFree()),
                ]),
            Section::make('Weather')
                ->description('Open-Meteo forecast for the venue, checked twice a day for sessions in the next 48 hours.')
                ->columns(3)
                ->visible(fn (Slot $record): bool => $record->currentWeather() !== null)
                ->schema([
                    TextEntry::make('weather_level')->label('Level')
                        ->state(fn (Slot $record): ?string => $record->currentWeather()?->severity->label())
                        ->badge()
                        ->color(fn (Slot $record): string => $record->currentWeather()?->severity->filamentColor() ?? 'gray')
                        ->icon(fn (Slot $record): ?Heroicon => $record->currentWeather()?->severity->filamentIcon()),
                    TextEntry::make('weather_summary')->label('Crossed thresholds')
                        ->state(fn (Slot $record): ?string => $record->currentWeather()?->summary() ?: null)
                        ->placeholder('None'),
                    TextEntry::make('weather_assessed')->label('Checked')
                        ->state(fn (Slot $record): ?string => $record->currentWeather()?->assessed_at->setTimezone(Slot::displayTimezone())->format('j M Y H:i')),
                ]),
            Section::make('Cancellation')
                ->columns(3)
                ->visible(fn (Slot $record): bool => $record->cancelled_at !== null)
                ->schema([
                    TextEntry::make('cancel_reason')->label('Reason')->columnSpan(2),
                    TextEntry::make('cancelled_at')->label('Cancelled')
                        ->dateTime('j M Y H:i', timezone: Slot::displayTimezone()),
                ]),
        ]);
    }
}
