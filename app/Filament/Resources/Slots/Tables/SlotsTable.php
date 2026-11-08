<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots\Tables;

use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Filament\Resources\Slots\SlotStatusActions;
use App\Filament\Support\TrainerOptions;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SlotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')->label('Date (Pakistan time)')
                    ->formatStateUsing(fn (Slot $record): string => $record->localStartsAt()->format('D j M Y'))
                    ->sortable(),
                TextColumn::make('time')->label('Time')
                    ->state(fn (Slot $record): string => $record->localStartsAt()->format('H:i').'–'.$record->localEndsAt()->format('H:i')),
                TextColumn::make('status')->badge(),
                TextColumn::make('weather_badge')->label('Weather')
                    ->state(fn (Slot $record): ?string => $record->currentWeather() === null ? null : 'Weather: '.$record->currentWeather()->severity->label())
                    ->badge()
                    ->color(fn (Slot $record): string => $record->currentWeather()?->severity->filamentColor() ?? 'gray')
                    ->icon(fn (Slot $record): ?Heroicon => $record->currentWeather()?->severity->filamentIcon())
                    ->tooltip(fn (Slot $record): ?string => $record->currentWeather()?->summary() ?: null)
                    ->placeholder('—'),
                TextColumn::make('source')->badge(),
                IconColumn::make('private_only')->label('Private only')->boolean()->trueIcon(Heroicon::OutlinedLockClosed)->falseIcon(null),
                TextColumn::make('trainer.name')->label('Trainer')->placeholder('—'),
                TextColumn::make('capacity')->label('Seats')->numeric(),
                TextColumn::make('seats_booked')->label('Booked')->numeric(),
                TextColumn::make('seats_held')->label('Held')->numeric(),
                TextColumn::make('seats_free')->label('Free')
                    ->state(fn (Slot $record): int => $record->seatsFree()),
            ])
            ->filters([
                TernaryFilter::make('upcoming')
                    ->label('When')
                    ->placeholder('All slots')
                    ->trueLabel('Upcoming')
                    ->falseLabel('Past')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('starts_at', '>', now()),
                        false: fn (Builder $query): Builder => $query->where('starts_at', '<=', now()),
                    ),
                SelectFilter::make('status')->options(SlotStatus::class),
                SelectFilter::make('source')->options(SlotSource::class),
                SelectFilter::make('trainer_id')->label('Trainer')->options(fn (): array => TrainerOptions::all()),
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->label('From (Pakistan date)'),
                        DatePicker::make('until')->label('Until (Pakistan date)'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $tz = Slot::displayTimezone();

                        if (is_string($data['from'] ?? null) && $data['from'] !== '') {
                            $query->where('starts_at', '>=', CarbonImmutable::parse($data['from'], $tz)->startOfDay()->utc());
                        }

                        if (is_string($data['until'] ?? null) && $data['until'] !== '') {
                            $query->where('starts_at', '<', CarbonImmutable::parse($data['until'], $tz)->addDay()->startOfDay()->utc());
                        }

                        return $query;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(SlotStatusActions::all()),
            ]);
    }
}
