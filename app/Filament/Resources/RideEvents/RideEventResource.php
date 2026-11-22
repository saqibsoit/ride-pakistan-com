<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEvents;

use App\Filament\Resources\RideEvents\Pages\CreateRideEvent;
use App\Filament\Resources\RideEvents\Pages\EditRideEvent;
use App\Filament\Resources\RideEvents\Pages\ListRideEvents;
use App\Filament\Resources\RideEvents\RelationManagers\OccurrencesRelationManager;
use App\Filament\Resources\RideEvents\RelationManagers\TiersRelationManager;
use App\Filament\Resources\RideEvents\Schemas\RideEventForm;
use App\Filament\Resources\RideEvents\Tables\RideEventsTable;
use App\Models\RideEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The event builder (decided 2026-11-22): any camp, workshop, trip or ride,
 * built here without code changes. Prices are tiers, dates are
 * occurrences (slots); both are managed below the form.
 */
class RideEventResource extends Resource
{
    protected static ?string $model = RideEvent::class;

    protected static ?string $modelLabel = 'event';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return RideEventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RideEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TiersRelationManager::class,
            OccurrencesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRideEvents::route('/'),
            'create' => CreateRideEvent::route('/create'),
            'edit' => EditRideEvent::route('/{record}/edit'),
        ];
    }
}
