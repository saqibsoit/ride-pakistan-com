<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rider.name')->label('Rider')->placeholder('Not named yet'),
                TextColumn::make('slot')->label('Slot')
                    ->state(fn (OrderItem $record): ?string => $record->slot?->label())
                    ->placeholder('Package (book later)'),
                TextColumn::make('unit_price_minor')->label('Price')
                    ->formatStateUsing(function (OrderItem $record): string {
                        $owner = $this->getOwnerRecord();

                        return Money::format($record->unit_price_minor, $owner instanceof Order ? $owner->currency : 'PKR');
                    }),
            ]);
    }
}
