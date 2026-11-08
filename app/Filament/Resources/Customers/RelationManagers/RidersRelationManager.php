<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\AgeBracket;
use App\Enums\ExperienceLevel;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Models\Rider;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Riders on an account. Emergency contact and notes are stored encrypted. */
class RidersRelationManager extends RelationManager
{
    protected static string $relationship = 'riders';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('age_bracket')->label('Age')->options(AgeBracket::class),
                Select::make('experience_level')->label('Experience')->options(ExperienceLevel::class),
                TextInput::make('emergency_contact_name')->label('Emergency contact')->maxLength(255),
                TextInput::make('emergency_contact_phone')->label('Emergency contact phone')
                    ->tel()
                    ->regex(CustomerForm::PHONE_PATTERN)
                    ->validationMessages(['regex' => 'Use the international format, for example +923001234567.'])
                    ->maxLength(16),
                Textarea::make('notes')->label('Notes for the trainer')
                    ->helperText('Health or confidence notes. Stored encrypted.')
                    ->rows(3)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('age_bracket')->label('Age')->badge()->placeholder('—'),
                TextColumn::make('experience_level')->label('Experience')->placeholder('—'),
                TextColumn::make('bookings_count')->label('Bookings')->counts('bookings'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('history')
                    ->label('History')
                    ->icon(Heroicon::OutlinedClock)
                    ->url(fn (Rider $record): string => route('team.riders.show', $record))
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
