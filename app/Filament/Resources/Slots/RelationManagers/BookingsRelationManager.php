<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots\RelationManagers;

use App\Filament\Resources\Bookings\BookingActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The slot's bookings, with the admin booking tools (BookingActions). Check-in arrives in milestone 8. */
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
                TextColumn::make('horse.name')->label('Horse')->placeholder('—'),
                TextColumn::make('order.reference')->label('Order')->placeholder('Credit or no charge'),
                TextColumn::make('ticket_code')->label('Ticket'),
            ])
            ->headerActions([BookingActions::manual($this->getOwnerRecord()->getKey())])
            ->recordActions(BookingActions::row());
    }
}
