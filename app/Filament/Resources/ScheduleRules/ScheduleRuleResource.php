<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduleRules;

use App\Filament\Resources\ScheduleRules\Pages\CreateScheduleRule;
use App\Filament\Resources\ScheduleRules\Pages\EditScheduleRule;
use App\Filament\Resources\ScheduleRules\Pages\ListScheduleRules;
use App\Filament\Resources\ScheduleRules\Schemas\ScheduleRuleForm;
use App\Filament\Resources\ScheduleRules\Tables\ScheduleRulesTable;
use App\Models\ScheduleRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ScheduleRuleResource extends Resource
{
    protected static ?string $model = ScheduleRule::class;

    protected static string|UnitEnum|null $navigationGroup = 'Schedule';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ScheduleRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduleRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduleRules::route('/'),
            'create' => CreateScheduleRule::route('/create'),
            'edit' => EditScheduleRule::route('/{record}/edit'),
        ];
    }
}
