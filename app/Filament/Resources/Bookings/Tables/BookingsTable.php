<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Tables;

use App\Enums\BookingStatus;
use App\Filament\Resources\Bookings\BookingActions;
use App\Models\Booking;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['slot', 'rider', 'user', 'order']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('slot.starts_at')
                    ->label('Session')
                    ->formatStateUsing(fn (Booking $record): string => $record->slot->label())
                    ->sortable(),
                TextColumn::make('rider.name')->label('Rider')->searchable(),
                TextColumn::make('user.email')->label('Customer')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('horse.name')->label('Horse')->placeholder('—'),
                IconColumn::make('is_private')->label('Private')->boolean(),
                TextColumn::make('source')
                    ->label('Paid by')
                    ->state(fn (Booking $record): string => match (true) {
                        $record->order !== null && ! $record->usesCredit() => 'Order '.$record->order->reference,
                        $record->usesCredit() => 'Package credit',
                        default => 'No charge',
                    }),
                TextColumn::make('ticket_code')->label('Ticket')->searchable()->copyable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(BookingStatus::class),
                Filter::make('upcoming')
                    ->label('Upcoming only')
                    ->query(fn (Builder $query): Builder => $query->whereHas('slot', fn (Builder $q) => $q->where('starts_at', '>', now())))
                    ->default(),
                TernaryFilter::make('is_private')->label('Private'),
            ])
            ->recordActions(BookingActions::row());
    }
}
