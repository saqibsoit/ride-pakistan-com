<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only for now; check-in and cancellations arrive in milestones 5 and 8. */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ticket_code')
            ->columns([
                TextColumn::make('rider.name')->label('Rider'),
                TextColumn::make('user.name')->label('Booked by'),
                TextColumn::make('status')->badge(),
                TextColumn::make('order.reference')->label('Order')->placeholder('Package credit'),
                TextColumn::make('ticket_code')->label('Ticket'),
            ]);
    }
}
