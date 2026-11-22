<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEventCategories;

use App\Filament\Resources\RideEventCategories\Pages\ManageRideEventCategories;
use App\Models\RideEventCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** The event categories, the filter chips on /events (decided 2026-11-22). */
class RideEventCategoryResource extends Resource
{
    protected static ?string $model = RideEventCategory::class;

    protected static ?string $modelLabel = 'event category';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(60),
            TextInput::make('slug')->required()->maxLength(60)->alphaDash()->unique(ignoreRecord: true)
                ->helperText('Used in /events?category={slug}.'),
            TextInput::make('sort')->integer()->default(0),
            Toggle::make('is_active')->label('Shown')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('slug'),
                TextColumn::make('events_count')->counts('events')->label('Events'),
                IconColumn::make('is_active')->label('Shown')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRideEventCategories::route('/')];
    }
}
