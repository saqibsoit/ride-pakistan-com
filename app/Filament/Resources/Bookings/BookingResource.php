<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings;

use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Tables\BookingsTable;
use App\Models\Booking;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every booking across slots. Staff read; admins also mark attendance,
 * move, cancel and add manual bookings (BookingActions). Bookings are
 * never created or edited with a plain form.
 */
class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'ticket_code';

    public static function table(Table $table): Table
    {
        return BookingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
        ];
    }
}
