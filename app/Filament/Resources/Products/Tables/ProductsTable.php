<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Actions\Products\ArchiveProduct;
use App\Actions\Products\DeleteProduct;
use App\Actions\Products\RestoreProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('price_minor')->label('Price')
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('per_session')->label('Per session')
                    ->state(fn (Product $record): ?string => $record->isPackage() ? Money::format($record->perSessionMinor()) : null)
                    ->placeholder('—'),
                TextColumn::make('sessions_count')->label('Sessions'),
                TextColumn::make('validity_days')->label('Valid (days)')->placeholder('—'),
                IconColumn::make('is_active')->label('On sale')->boolean(),
                IconColumn::make('is_featured')->label('Featured')->boolean(),
                TextColumn::make('archived_at')->label('Archived')
                    ->dateTime('j M Y', (string) config('app.display_timezone'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('archived')
                    ->label('Archived')
                    ->placeholder('Current products')
                    ->trueLabel('Archived only')
                    ->falseLabel('All products')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                self::archiveAction(),
                self::restoreAction(),
                self::deleteAction(),
            ]);
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('warning')
            ->visible(fn (Product $record): bool => Gate::allows('archive', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (Product $record): string => 'Archive '.$record->name.'?')
            ->modalDescription('It goes off sale and disappears from the site and this list. Orders, tickets and credit packs keep working and keep their price and validity. You can restore it later.')
            ->action(function (Product $record): void {
                Gate::authorize('archive', $record);
                app(ArchiveProduct::class)->handle($record, self::actor());
                Notification::make()->success()->title('Archived')->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restoreFromArchive')
            ->label('Restore')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (Product $record): bool => Gate::allows('restore', $record))
            ->requiresConfirmation()
            ->modalDescription('It comes back to the list switched off. Turn "On sale" on when it should be bookable again.')
            ->action(function (Product $record): void {
                Gate::authorize('restore', $record);
                app(RestoreProduct::class)->handle($record, self::actor());
                Notification::make()->success()->title('Restored (off sale)')->send();
            });
    }

    /** Only for products that were never ordered; the rest are archived. */
    public static function deleteAction(): Action
    {
        return Action::make('deleteProduct')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Product $record): bool => Gate::allows('delete', $record))
            ->requiresConfirmation()
            ->modalDescription('This product was never ordered, so it can be deleted for good. FAQs linked to it become general FAQs.')
            ->action(function (Product $record): void {
                Gate::authorize('delete', $record);
                app(DeleteProduct::class)->handle($record, self::actor());
                Notification::make()->success()->title('Deleted')->send();
            });
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
