<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vouchers;

use App\Actions\Vouchers\DeliverVoucher;
use App\Actions\Vouchers\UpdateVoucherRecipient;
use App\Actions\Vouchers\VoucherBalance;
use App\Enums\VoucherStatus;
use App\Exceptions\VoucherRejected;
use App\Filament\Resources\Vouchers\Pages\ListVouchers;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Gift vouchers (milestone 10e, admins only): issued, used, balance and
 * expiry. Never edited by hand: void, extend, resend and recipient
 * changes go through their Actions with a reason where money is involved.
 * "Issue voucher" (list header) creates one without a purchase.
 */
class VoucherResource extends Resource
{
    public const EXPIRING_DAYS = 30;

    protected static ?string $model = Voucher::class;

    protected static string|UnitEnum|null $navigationGroup = 'Sales & promotions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Gift vouchers';

    protected static ?string $modelLabel = 'gift voucher';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'code';

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['voucherOrder.user', 'issuer']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->searchable()->copyable()
                    ->formatStateUsing(fn (Voucher $record): string => $record->formattedCode())
                    ->description(fn (Voucher $record): ?string => $record->label),
                TextColumn::make('status')->badge(),
                TextColumn::make('initial_minor')->label('Value')
                    ->formatStateUsing(fn (Voucher $record): string => Money::format($record->initial_minor, $record->currency)),
                TextColumn::make('balance_minor')->label('Left')->sortable()
                    ->formatStateUsing(fn (Voucher $record): string => Money::format($record->balance_minor, $record->currency)),
                TextColumn::make('recipient_name')->label('Recipient')->searchable(['recipient_name', 'recipient_email'])
                    ->description(fn (Voucher $record): ?string => $record->recipient_email),
                TextColumn::make('source')->label('Bought by')
                    ->state(fn (Voucher $record): string => $record->voucherOrder !== null
                        ? (string) ($record->voucherOrder->user->email.' · '.$record->voucherOrder->reference)
                        : 'Issued by '.($record->issuer->name ?? 'an admin')),
                TextColumn::make('delivered_at')->label('Sent')->dateTime('j M Y H:i', $tz)->placeholder('Not yet'),
                TextColumn::make('expires_at')->label('Expires')->dateTime('j M Y', $tz)->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(VoucherStatus::class),
                Filter::make('expiring')
                    ->label('Expiring within '.self::EXPIRING_DAYS.' days, balance left')
                    ->query(fn (Builder $query): Builder => $query->where('status', VoucherStatus::Active)
                        ->where('balance_minor', '>', 0)
                        ->whereBetween('expires_at', [now(), now()->addDays(self::EXPIRING_DAYS)])),
                Filter::make('not_sent')->label('Not sent yet')->query(fn (Builder $query): Builder => $query->whereNull('delivered_at')),
            ])
            ->recordActions([
                self::historyAction(),
                ActionGroup::make([
                    self::resendAction(),
                    self::recipientAction(),
                    self::extendAction(),
                    self::voidAction(),
                ]),
            ])
            ->emptyStateHeading('No gift vouchers yet');
    }

    private static function historyAction(): Action
    {
        return Action::make('history')
            ->label('History')
            ->icon(Heroicon::OutlinedClock)
            ->modalHeading(fn (Voucher $record): string => 'Voucher '.$record->formattedCode())
            ->modalContent(fn (Voucher $record): View => view('filament.vouchers.history', [
                'voucher' => $record->load(['ledger' => fn ($q) => $q->latest('id'), 'ledger.actor', 'voucherOrder']),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private static function resendAction(): Action
    {
        return Action::make('resend')
            ->label('Email to recipient')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (Voucher $record): bool => self::admin()?->can('manage', $record) === true && $record->status === VoucherStatus::Active)
            ->requiresConfirmation()
            ->modalDescription(fn (Voucher $record): string => 'Sends the voucher email to '.$record->recipient_email.' now, even if a send date is set.')
            ->action(function (Voucher $record): void {
                $admin = self::admin();

                if ($admin !== null && app(DeliverVoucher::class)->resend($record, $admin)) {
                    Notification::make()->success()->title('Voucher emailed to '.$record->recipient_email)->send();

                    return;
                }

                Notification::make()->danger()->title('Not sent')->body('Only active vouchers with a recipient email can be sent.')->send();
            });
    }

    private static function recipientAction(): Action
    {
        return Action::make('recipient')
            ->label('Change recipient')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (Voucher $record): bool => self::admin()?->can('manage', $record) === true && $record->delivered_at === null)
            ->fillForm(fn (Voucher $record): array => ['name' => $record->recipient_name, 'email' => $record->recipient_email])
            ->schema([
                TextInput::make('name')->label('Recipient name')->required()->maxLength(120),
                TextInput::make('email')->label('Recipient email')->email()->required()->maxLength(190),
            ])
            ->action(function (Voucher $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    app(UpdateVoucherRecipient::class)->forVoucher($record, $admin, (string) $data['name'], (string) $data['email']);
                } catch (VoucherRejected $e) {
                    Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Recipient changed')->send();
            });
    }

    private static function extendAction(): Action
    {
        return Action::make('extend')
            ->label('Extend expiry')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (Voucher $record): bool => self::admin()?->can('manage', $record) === true)
            ->schema([
                DatePicker::make('until')->label('New expiry date (Pakistan time, end of day)')->required()->minDate(now()->addDay()),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (Voucher $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                $until = CarbonImmutable::parse((string) $data['until'], (string) config('app.display_timezone'))->endOfDay()->utc();

                try {
                    app(VoucherBalance::class)->extend($record, $admin, $until, (string) ($data['reason'] ?? ''));
                } catch (VoucherRejected $e) {
                    Notification::make()->danger()->title('Not extended')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Expiry extended')->send();
            });
    }

    private static function voidAction(): Action
    {
        return Action::make('void')
            ->label('Void')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Voucher $record): bool => self::admin()?->can('manage', $record) === true)
            ->modalDescription(fn (Voucher $record): string => 'Cancels the remaining '.Money::format($record->balance_minor, $record->currency).'. The voucher can no longer be used. This does not refund anyone.')
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (Voucher $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    app(VoucherBalance::class)->void($record, $admin, (string) ($data['reason'] ?? ''));
                } catch (VoucherRejected $e) {
                    Notification::make()->danger()->title('Not voided')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Voucher voided')->send();
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
            'index' => ListVouchers::route('/'),
        ];
    }
}
