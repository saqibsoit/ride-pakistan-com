<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEvents\RelationManagers;

use App\Models\RideEventTier;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Prices of an event (decided 2026-11-22): "Per person", or tiers such as
 * adult and child, each with an optional seat cap per date. Changes apply
 * to new bookings only; orders keep the price they were booked at. A tier
 * someone booked can't be deleted.
 */
class TiersRelationManager extends RelationManager
{
    protected static string $relationship = 'tiers';

    protected static ?string $title = 'Prices';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextInput::make('name')->required()->maxLength(60)->default('Per person'),
                TextInput::make('price_minor')->label('Price per person (PKR)')->required()->integer()->minValue(0)->maxValue(10_000_000)
                    ->formatStateUsing(fn (mixed $state): ?int => is_numeric($state) ? intdiv((int) $state, 100) : null)
                    ->dehydrateStateUsing(fn (mixed $state): int => (int) $state * 100),
                TextInput::make('seats_per_occurrence')->label('Places per date (optional)')->integer()->minValue(1)->maxValue(500)
                    ->helperText('Leave empty to share the date’s capacity.'),
                TextInput::make('sort')->integer()->default(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('price_minor')->label('Price')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('seats_per_occurrence')->label('Places per date')->placeholder('Shared'),
            ])
            ->headerActions([CreateAction::make()->label('Add price')])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (RideEventTier $record): bool => ! $record->orderItems()->exists() && $record->event->tiers()->count() > 1),
            ]);
    }
}
