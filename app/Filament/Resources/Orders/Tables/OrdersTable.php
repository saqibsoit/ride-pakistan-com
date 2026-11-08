<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->copyable(),
                TextColumn::make('user.name')->label('Customer')->searchable(),
                TextColumn::make('product_name')->label('Product'),
                TextColumn::make('status')->badge(),
                IconColumn::make('needs_review')->label('Review')
                    ->state(fn (Order $record): bool => isset($record->meta['needs_review']))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedExclamationTriangle)
                    ->trueColor('danger')
                    ->falseIcon(null)
                    ->tooltip(fn (Order $record): ?string => isset($record->meta['needs_review']) ? 'Needs review: '.$record->meta['needs_review'] : null),
                TextColumn::make('payment_method')->label('Method')->placeholder('Not chosen'),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Order $record): string => Money::format($record->amount_minor, $record->currency)),
                TextColumn::make('created_at')->label('Created')->dateTime('j M Y H:i', timezone: $tz)->sortable(),
                TextColumn::make('paid_at')->label('Paid')->dateTime('j M Y H:i', timezone: $tz)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::class),
                SelectFilter::make('payment_method')->label('Method')->options(PaymentMethod::class),
                Filter::make('needs_review')->label('Needs review')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('meta->needs_review')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
