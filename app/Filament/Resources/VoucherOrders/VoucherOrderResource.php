<?php

declare(strict_types=1);

namespace App\Filament\Resources\VoucherOrders;

use App\Actions\Payments\BankRefund;
use App\Actions\Vouchers\RecordVoucherTransferRefund;
use App\Enums\PaymentMethod;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\BookingRejected;
use App\Filament\Resources\VoucherOrders\Pages\ListVoucherOrders;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Support\Money;
use App\Support\SessionTimes;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                self::transferRefund(),
            ]);
    }

    /**
     * A gift voucher bought by bank transfer, refunded by the team's own
     * transfer (RecordVoucherTransferRefund): at most the unspent balance.
     */
    public static function transferRefund(): Action
    {
        return Action::make('transfer_refund')
            ->label('Record bank refund')
            ->icon(Heroicon::OutlinedBuildingLibrary)
            ->color('danger')
            ->visible(fn (VoucherOrder $record): bool => self::admin() !== null
                && $record->status === VoucherOrderStatus::Paid
                && $record->payment_method === PaymentMethod::BankTransfer)
            ->modalDescription(fn (VoucherOrder $record): string => 'Make the transfer to the buyer in online banking first, then record it here. It comes off the voucher’s unspent balance; at zero the voucher stops working. Refundable now: '
                .Money::format(RecordVoucherTransferRefund::refundableMinor($record), $record->currency).'.')
            ->schema(fn (VoucherOrder $record): array => [
                TextInput::make('amount')
                    ->label('Amount transferred (PKR)')
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->maxValue(intdiv(RecordVoucherTransferRefund::refundableMinor($record), Money::MINOR_PER_MAJOR))
                    ->default(intdiv(RecordVoucherTransferRefund::refundableMinor($record), Money::MINOR_PER_MAJOR)),
                TextInput::make('bank_reference')
                    ->label('Bank reference of your transfer')
                    ->required()
                    ->maxLength(BankRefund::MAX_REFERENCE_LENGTH),
                DatePicker::make('transferred_on')
                    ->label('Transfer date (Pakistan)')
                    ->required()
                    ->native(false)
                    ->default(fn (): string => SessionTimes::now()->toDateString())
                    ->maxDate(fn (): string => SessionTimes::now()->toDateString()),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->requiresConfirmation()
            ->action(function (VoucherOrder $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                $amount = (int) ($data['amount'] ?? 0) * Money::MINOR_PER_MAJOR;

                try {
                    app(RecordVoucherTransferRefund::class)->handle(
                        $record,
                        $admin,
                        $amount,
                        (string) ($data['bank_reference'] ?? ''),
                        CarbonImmutable::parse((string) $data['transferred_on'], (string) config('app.display_timezone')),
                        (string) ($data['reason'] ?? ''),
                    );
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Bank refund of '.Money::format($amount, $record->currency).' recorded')->send();
            });
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
