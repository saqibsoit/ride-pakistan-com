<?php

declare(strict_types=1);

namespace App\Filament\Resources\Horses\Tables;

use App\Actions\Horses\RestoreHorse;
use App\Actions\Horses\RetireHorse;
use App\Enums\HorseLevel;
use App\Models\Horse;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class HorsesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('suitable_levels')->label('Suits')
                    ->state(fn (Horse $record): string => self::levels($record->suitable_levels)),
                TextColumn::make('max_sessions_per_day')->label('Max a day'),
                IconColumn::make('is_active')->label('Working')->boolean(),
                TextColumn::make('bookings_count')->label('Sessions')->counts('bookings'),
                TextColumn::make('retired_at')->label('Retired')
                    ->dateTime('j M Y', (string) config('app.display_timezone'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('retired')
                    ->label('Retired')
                    ->placeholder('Current horses')
                    ->trueLabel('Retired only')
                    ->falseLabel('All horses')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('retired_at'),
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query->whereNull('retired_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                self::retireAction(),
                self::restoreAction(),
                self::deleteAction(),
            ]);
    }

    /** @param  Collection<int, HorseLevel>  $levels */
    public static function levels(Collection $levels): string
    {
        return $levels->map(fn (HorseLevel $level): string => $level->label())->implode(', ');
    }

    public static function retireAction(): Action
    {
        return Action::make('retire')
            ->label('Retire')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('warning')
            ->visible(fn (Horse $record): bool => Gate::allows('retire', $record))
            ->modalHeading(fn (Horse $record): string => 'Retire '.$record->name.'?')
            ->modalDescription('The horse leaves the assignment lists for good. Sessions it carried keep the record.')
            ->schema([
                Textarea::make('reason')->required()->maxLength(500),
            ])
            ->action(function (Horse $record, array $data): void {
                Gate::authorize('retire', $record);
                app(RetireHorse::class)->handle($record, self::actor(), (string) $data['reason']);
                Notification::make()->success()->title('Retired')->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restoreHorse')
            ->label('Bring back')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (Horse $record): bool => Gate::allows('restore', $record))
            ->requiresConfirmation()
            ->modalDescription('The horse comes back switched off. Turn "Working" on when it can take riders again.')
            ->action(function (Horse $record): void {
                Gate::authorize('restore', $record);
                app(RestoreHorse::class)->handle($record, self::actor());
                Notification::make()->success()->title('Back (not working yet)')->send();
            });
    }

    /** Only for horses that never carried a rider; the rest are retired. */
    public static function deleteAction(): Action
    {
        return Action::make('deleteHorse')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Horse $record): bool => Gate::allows('delete', $record))
            ->requiresConfirmation()
            ->modalDescription('This horse never carried a rider here, so it can be deleted for good.')
            ->action(function (Horse $record): void {
                Gate::authorize('delete', $record);
                $record->delete();
                Notification::make()->success()->title('Deleted')->send();
            });
    }

    private static function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
