<?php

declare(strict_types=1);

namespace App\Filament\Resources\Horses;

use App\Filament\Resources\Horses\Pages\CreateHorse;
use App\Filament\Resources\Horses\Pages\EditHorse;
use App\Filament\Resources\Horses\Pages\ListHorses;
use App\Filament\Resources\Horses\RelationManagers\UnavailabilitiesRelationManager;
use App\Filament\Resources\Horses\Schemas\HorseForm;
use App\Filament\Resources\Horses\Tables\HorsesTable;
use App\Models\Horse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Horses (milestone 10b, admins): levels, daily limit, rest and vet days.
 * Assignments happen on the run sheet and in the bookings list.
 */
class HorseResource extends Resource
{
    protected static ?string $model = Horse::class;

    protected static string|UnitEnum|null $navigationGroup = 'Schedule';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return HorseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HorsesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            UnavailabilitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHorses::route('/'),
            'create' => CreateHorse::route('/create'),
            'edit' => EditHorse::route('/{record}/edit'),
        ];
    }
}
