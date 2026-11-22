<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEvents\Tables;

use App\Actions\Events\ChangeRideEventStatus;
use App\Actions\Events\DuplicateRideEvent;
use App\Enums\RideEventStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Filament\Resources\RideEvents\RideEventResource;
use App\Models\RideEvent;
use App\Models\User;
use App\Support\Events\EventAvailability;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RideEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'tiers']))
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('title')->searchable()->description(fn (RideEvent $record): string => '/events/'.$record->slug),
                TextColumn::make('category.name')->label('Category')->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('availability')->label('Dates')
                    ->state(fn (RideEvent $record): string => EventAvailability::for($record)->adminLabel()),
                TextColumn::make('price')->label('From')
                    ->state(fn (RideEvent $record): string => Money::format($record->fromPriceMinor()).($record->takesDeposit() ? ' ('.$record->deposit_percent.'% deposit)' : '')),
                IconColumn::make('is_featured')->label('Featured')->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')->options(RideEventStatus::class)
                    ->default(null)
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null) === null
                        ? $query->where('status', '!=', RideEventStatus::Archived)
                        : $query->where('status', $data['value'])),
            ])
            ->recordActions([
                EditAction::make(),
                self::duplicateAction(),
            ]);
    }

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->visible(fn (RideEvent $record): bool => Gate::allows('duplicate', $record))
            ->requiresConfirmation()
            ->modalDescription('Copies the content, prices, questions, photos and rules into a new draft. Then add its dates and publish it.')
            ->action(function (RideEvent $record, Action $action): void {
                Gate::authorize('duplicate', $record);
                $copy = app(DuplicateRideEvent::class)->handle($record, self::actor());
                Notification::make()->success()->title('Copied as a draft')->send();
                $action->redirect(RideEventResource::getUrl('edit', ['record' => $copy]));
            });
    }

    /** Publish, unpublish, archive or restore. */
    public static function statusAction(RideEventStatus $to, string $label, Heroicon $icon, string $description): Action
    {
        return Action::make('status_'.$to->value)
            ->label($label)
            ->icon($icon)
            ->color($to === RideEventStatus::Archived ? 'warning' : ($to === RideEventStatus::Published ? 'success' : 'gray'))
            ->visible(fn (RideEvent $record): bool => $record->status->canTransitionTo($to) && Gate::allows('changeStatus', $record))
            ->requiresConfirmation()
            ->modalDescription($description)
            ->action(function (RideEvent $record) use ($to): void {
                Gate::authorize('changeStatus', $record);

                try {
                    app(ChangeRideEventStatus::class)->handle($record, $to, self::actor());
                    Notification::make()->success()->title($to->getLabel())->send();
                } catch (InvalidStatusTransition $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    /** Only for an event nobody booked; the rest are archived. */
    public static function deleteAction(): Action
    {
        return Action::make('deleteEvent')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (RideEvent $record): bool => Gate::allows('delete', $record))
            ->requiresConfirmation()
            ->modalDescription('Nobody booked this event, so it can be deleted for good, with its dates.')
            ->action(function (RideEvent $record): void {
                Gate::authorize('delete', $record);

                DB::transaction(function () use ($record): void {
                    $record->occurrences()->each(fn ($slot) => $slot->delete());
                    $record->delete();
                });

                Notification::make()->success()->title('Deleted')->send();
            });
    }

    private static function actor(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
