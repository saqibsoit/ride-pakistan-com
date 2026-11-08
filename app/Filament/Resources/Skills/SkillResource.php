<?php

declare(strict_types=1);

namespace App\Filament\Resources\Skills;

use App\Filament\Resources\Skills\Pages\CreateSkill;
use App\Filament\Resources\Skills\Pages\EditSkill;
use App\Filament\Resources\Skills\Pages\ListSkills;
use App\Models\Skill;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Rider skill checklist (milestone 10d). Trainers tick these on the run
 * sheet after a session and customers see them in their account. Switch a
 * skill off to retire it; riders who reached it keep it.
 */
class SkillResource extends Resource
{
    protected static ?string $model = Skill::class;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $navigationLabel = 'Rider skills';

    protected static ?string $modelLabel = 'skill';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->required()->maxLength(120)
                    ->helperText('As customers see it, e.g. "Rising trot".'),
                Toggle::make('is_active')->label('On the checklist')->default(true)
                    ->helperText('Off hides it from the run sheet and from riders who haven\'t reached it.'),
                Textarea::make('description')->maxLength(500)->rows(2)->columnSpanFull()
                    ->helperText('What the rider can do when this is ticked. Shown to the trainer.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (Skill $record): ?string => $record->description),
                TextColumn::make('rider_skills_count')->label('Riders')->counts('riderSkills'),
                IconColumn::make('is_active')->label('On the checklist')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (Skill $record): bool => auth()->user()?->can('delete', $record) === true),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSkills::route('/'),
            'create' => CreateSkill::route('/create'),
            'edit' => EditSkill::route('/{record}/edit'),
        ];
    }
}
