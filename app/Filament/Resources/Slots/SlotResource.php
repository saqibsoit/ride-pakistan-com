<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots;

use App\Filament\Resources\Slots\Pages\ListSlots;
use App\Filament\Resources\Slots\Pages\SlotCalendar;
use App\Filament\Resources\Slots\Pages\ViewSlot;
use App\Filament\Resources\Slots\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Slots\Schemas\SlotInfolist;
use App\Filament\Resources\Slots\Tables\SlotsTable;
use App\Models\Slot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Generated sessions. No create, edit or delete: slots come from schedule
 * rules (GenerateSlots) and change status through SlotStatusActions.
 */
class SlotResource extends Resource
{
    protected static ?string $model = Slot::class;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 20;

    /**
     * @return Builder<Slot>
     */
    public static function getEloquentQuery(): Builder
    {
        return Slot::query()->withSeatCounts();
    }

    public static function infolist(Schema $schema): Schema
    {
        return SlotInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SlotsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSlots::route('/'),
            'calendar' => SlotCalendar::route('/calendar'),
            'view' => ViewSlot::route('/{record}'),
        ];
    }
}
