<?php

declare(strict_types=1);

namespace App\Filament\Resources\Horses\RelationManagers;

use App\Enums\UnavailabilityReason;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Rest and vet days (Pakistan dates, milestone 10b). A horse can't be
 * assigned to any session on these dates. Admins only (HorsePolicy).
 */
class UnavailabilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'unavailabilities';

    protected static ?string $title = 'Unavailable days';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                DatePicker::make('date')->label('Date (Pakistan)')->required()
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('horse_id', $this->getOwnerRecord()->getKey()))
                    ->validationMessages(['unique' => 'This horse already has that day off.']),
                Select::make('reason')->options(UnavailabilityReason::class)->required()->default(UnavailabilityReason::Rest->value),
                TextInput::make('note')->maxLength(255)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        $today = now()->setTimezone((string) config('app.display_timezone'))->format('Y-m-d');

        return $table
            ->recordTitleAttribute('date')
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')->date('D j M Y'),
                TextColumn::make('reason')->badge(),
                TextColumn::make('note')->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('upcoming')->label('Upcoming only')->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereDate('date', '>=', $today),
                        false: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->headerActions([
                CreateAction::make()->label('Add day off')
                    ->mutateDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
