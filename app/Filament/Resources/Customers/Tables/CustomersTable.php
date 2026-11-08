<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['riders', 'orders']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->placeholder('Not given yet'),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->searchable()->placeholder('—'),
                TextColumn::make('role')->badge(),
                TextColumn::make('riders_count')->label('Riders')->numeric(),
                TextColumn::make('orders_count')->label('Orders')->numeric(),
                TextColumn::make('created_at')->label('Joined')
                    ->formatStateUsing(fn (User $record): string => $record->created_at?->setTimezone((string) config('app.display_timezone'))->format('j M Y') ?? '')
                    ->sortable(),
                TextColumn::make('deleted_at')->label('Account')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (User $record): string => $record->isAnonymised() ? 'Anonymised' : 'Deletion requested')
                    ->placeholder('Active'),
            ])
            ->filters([
                SelectFilter::make('role')->options(UserRole::class),
                TrashedFilter::make()->label('Deleted accounts'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
