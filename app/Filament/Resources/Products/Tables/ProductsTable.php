<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('price_minor')->label('Price')
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('sessions_count')->label('Sessions'),
                TextColumn::make('validity_days')->label('Valid (days)')->placeholder('—'),
                IconColumn::make('is_active')->label('On sale')->boolean(),
                IconColumn::make('is_featured')->label('Featured')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
