<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditPacks;

use App\Actions\Dashboard\TodaySummary;
use App\Enums\CreditPackStatus;
use App\Filament\Resources\CreditPacks\Pages\ListCreditPacks;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CreditPack;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every credit pack, read-only (milestone 10b, admins: CreditPackPolicy).
 * The dashboard's "expiring" card links to the `expiring` filter. Packs
 * are extended from the customer's Credits tab (ExtendCreditPack).
 */
class CreditPackResource extends Resource
{
    public const EXPIRING_DAYS = TodaySummary::EXPIRING_DAYS;

    protected static ?string $model = CreditPack::class;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Credit packs';

    protected static ?string $slug = 'credit-packs';

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'order']))
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('user.email')->label('Customer')->searchable(),
                TextColumn::make('order.product_name')->label('Package'),
                TextColumn::make('credits')->label('Credits left')
                    ->state(fn (CreditPack $record): string => $record->creditsRemaining().' of '.$record->credits_total),
                TextColumn::make('status')->badge(),
                TextColumn::make('expires_at')->label('Expires')->dateTime('j M Y', $tz)->sortable(),
                TextColumn::make('order.reference')->label('Order')->searchable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(CreditPackStatus::class),
                Filter::make('expiring')
                    ->label('Expiring within '.self::EXPIRING_DAYS.' days, credits left')
                    ->query(fn (Builder $query): Builder => $query->whereIn('id', CreditPack::query()->expiringWithin(self::EXPIRING_DAYS)->select('id'))),
            ])
            ->recordActions([
                Action::make('customer')
                    ->label('Customer')
                    ->icon(Heroicon::OutlinedUser)
                    ->url(fn (CreditPack $record): string => CustomerResource::getUrl('edit', ['record' => $record->user_id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditPacks::route('/'),
        ];
    }
}
