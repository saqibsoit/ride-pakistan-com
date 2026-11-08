<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduleRules\Tables;

use App\Filament\Resources\ScheduleRules\Schemas\ScheduleRuleForm;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScheduleRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('day_of_week')
            ->columns([
                TextColumn::make('day_of_week')->label('Day')
                    ->formatStateUsing(fn (int $state): string => ScheduleRuleForm::DAYS[$state] ?? (string) $state),
                TextColumn::make('start_time')->label('Starts')->time('H:i'),
                TextColumn::make('end_time')->label('Ends')->time('H:i'),
                TextColumn::make('capacity')->label('Riders'),
                TextColumn::make('valid_from')->date()->placeholder('—'),
                TextColumn::make('valid_to')->date()->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
