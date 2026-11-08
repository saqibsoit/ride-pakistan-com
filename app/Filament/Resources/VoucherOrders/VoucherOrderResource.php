<?php

declare(strict_types=1);

namespace App\Filament\Resources\VoucherOrders;

use App\Enums\VoucherOrderStatus;
use App\Filament\Resources\VoucherOrders\Pages\ListVoucherOrders;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Gift voucher purchases (milestone 10e, admins only, read-only): who
 * bought what, how it was paid and whether it needs a look (payment
 * mismatch, refund made in Stripe). Transfers are reviewed on "Verify
 * voucher transfers".
 */
class VoucherOrderResource extends Resource
{
    protected static ?string $model = VoucherOrder::class;

    protected static string|UnitEnum|null $navigationGroup = 'Sales & promotions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Voucher purchases';

    protected static ?string $modelLabel = 'voucher purchase';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'voucher-purchases';

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'voucher']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->copyable(),
                TextColumn::make('status')->badge(),
                IconColumn::make('needs_review')->label('Review')->boolean()
                    ->trueIcon(Heroicon::OutlinedExclamationTriangle)->trueColor('danger')
                    ->falseIcon(Heroicon::OutlinedMinus)->falseColor('gray')
                    ->tooltip(fn (VoucherOrder $record): ?string => $record->needs_review ? (string) ($record->meta['review_reason'] ?? 'Needs review') : null),
                TextColumn::make('user.email')->label('Buyer')->searchable(),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (VoucherOrder $record): string => Money::format($record->amount_minor, $record->currency))
                    ->description(fn (VoucherOrder $record): ?string => $record->product_name),
                TextColumn::make('payment_method')->label('Paid by')->badge()->placeholder('—'),
                TextColumn::make('recipient_name')->label('For')->description(fn (VoucherOrder $record): ?string => $record->recipient_email),
                TextColumn::make('send_on')->label('Send on')->date('j M Y')->placeholder('Straight away'),
                TextColumn::make('voucher.code')->label('Voucher')->placeholder('Not issued'),
                TextColumn::make('created_at')->label('Started')->dateTime('j M Y H:i', $tz)->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(VoucherOrderStatus::class),
                TernaryFilter::make('needs_review')->label('Needs review'),
            ])
            ->recordActions([
                Action::make('reviewed')
                    ->label('Mark reviewed')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (VoucherOrder $record): bool => $record->needs_review && self::admin() !== null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (VoucherOrder $record): string => (string) ($record->meta['review_reason'] ?? ''))
                    ->action(function (VoucherOrder $record): void {
                        $record->needs_review = false;
                        $record->save();

                        activity('vouchers')
                            ->performedOn($record)
                            ->causedBy(self::admin())
                            ->event('review_cleared')
                            ->log('Review flag cleared for voucher purchase '.$record->reference);

                        Notification::make()->success()->title('Marked as reviewed')->send();
                    }),
            ]);
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin() ? $user : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVoucherOrders::route('/'),
        ];
    }
}
