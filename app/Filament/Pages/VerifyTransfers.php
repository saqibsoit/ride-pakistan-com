<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Payments\ApproveBankTransfer;
use App\Actions\Payments\ExtendTransferHold;
use App\Actions\Payments\RejectBankTransfer;
use App\Enums\BankTransferStatus;
use App\Enums\OrderStatus;
use App\Exceptions\BookingRejected;
use App\Http\Controllers\TransferProofController;
use App\Models\BankTransfer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Money;
use App\Support\TransferProofs;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Bank transfer verification queue (PLAN §3.2 step 4), soonest deadline
 * first. Admins only: approve (FulfilOrder), reject with a reason, or
 * extend the deadline. Proofs open through a short-lived signed URL.
 */
class VerifyTransfers extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.verify-transfers';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $title = 'Verify transfers';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('review', BankTransfer::class);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Order::query()->where('status', OrderStatus::AwaitingVerification)->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->query(Order::query()
                ->whereIn('status', [OrderStatus::AwaitingVerification, OrderStatus::AwaitingTransfer])
                ->with(['user', 'product', 'items.slot', 'items.rider', 'bankTransfers']))
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('reference')->searchable()->copyable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('user.name')->label('Customer')->searchable()
                    ->description(fn (Order $record): string => (string) $record->user->email),
                TextColumn::make('amount_minor')->label('Expected')
                    ->formatStateUsing(fn (Order $record): string => Money::format($record->amount_minor, $record->currency)),
                TextColumn::make('transaction_ref')->label('Transaction ID')
                    ->state(fn (Order $record): ?string => self::transfer($record)?->transaction_ref)
                    ->placeholder('—'),
                TextColumn::make('riders')->label('Riders')
                    ->state(fn (Order $record): string => $record->items->map(fn (OrderItem $i): ?string => $i->rider?->name)->filter()->join(', '))
                    ->placeholder('—'),
                TextColumn::make('slots')->label('Session')
                    ->state(fn (Order $record): string => $record->items->map(fn (OrderItem $i): ?string => $i->slot?->label())->filter()->unique()->join('; '))
                    ->placeholder('Package, no session'),
                TextColumn::make('submitted_at')->label('Uploaded')
                    ->state(fn (Order $record): ?CarbonImmutable => self::transfer($record)?->submitted_at)
                    ->dateTime('j M H:i', timezone: $tz)
                    ->since()
                    ->placeholder('Not yet'),
                TextColumn::make('expires_at')->label('Deadline')->sortable()
                    ->dateTime('D j M H:i', timezone: $tz)
                    ->badge()
                    ->color(fn (Order $record): string => $record->expires_at !== null && $record->expires_at->isPast() ? 'danger' : 'gray')
                    ->description(fn (Order $record): ?string => $record->expires_at !== null && $record->expires_at->isPast() ? 'Overdue' : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        OrderStatus::AwaitingVerification->value => 'Proof uploaded',
                        OrderStatus::AwaitingTransfer->value => 'Waiting for the customer',
                    ])
                    ->default(OrderStatus::AwaitingVerification->value),
            ])
            ->recordActions([
                $this->proofAction(),
                $this->approveAction(),
                $this->rejectAction(),
                $this->extendAction(),
            ])
            ->emptyStateHeading('No transfers to verify')
            ->emptyStateDescription('New uploads appear here, soonest deadline first.');
    }

    private function proofAction(): Action
    {
        return Action::make('view_proof')
            ->label('Proof')
            ->icon(Heroicon::OutlinedPhoto)
            ->visible(fn (Order $record): bool => self::transfer($record) !== null)
            ->modalHeading(fn (Order $record): string => 'Transfer proof: '.$record->reference)
            ->modalContent(function (Order $record): View {
                $transfer = self::transfer($record);

                return view('filament.transfers.proof', [
                    'order' => $record,
                    'transfer' => $transfer,
                    'url' => $transfer?->screenshot_path === null ? null : TransferProofController::signedUrl($transfer),
                    'pdf' => $transfer?->screenshot_path !== null && TransferProofs::isPdf($transfer->screenshot_path),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Order $record): bool => self::reviewable($record))
            ->requiresConfirmation()
            ->modalDescription(fn (Order $record): string => 'Only approve once '.Money::format($record->amount_minor, $record->currency)
                .' with reference '.$record->reference.' is in the account. This confirms the booking and emails the customer.')
            ->action(function (Order $record): void {
                $admin = self::admin();
                $transfer = self::transfer($record);

                if ($admin === null || $transfer === null) {
                    return;
                }

                try {
                    app(ApproveBankTransfer::class)->handle($transfer, $admin);
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not approved')->body($e->getMessage().' Reject the transfer and refund it by bank, or move the booking.')->send();

                    return;
                }

                Notification::make()->success()->title('Approved: '.$record->reference.' is confirmed')->send();
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record): bool => self::reviewable($record))
            ->schema([
                Textarea::make('reason')->label('Reason (sent to the customer)')->required()->maxLength(500)->rows(3),
            ])
            ->action(function (Order $record, array $data): void {
                $admin = self::admin();
                $transfer = self::transfer($record);

                if ($admin === null || $transfer === null) {
                    return;
                }

                try {
                    app(RejectBankTransfer::class)->handle($transfer, $admin, (string) ($data['reason'] ?? ''));
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not rejected')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Rejected: seats released and the customer emailed')->send();
            });
    }

    private function extendAction(): Action
    {
        $tz = (string) config('app.display_timezone');

        return Action::make('extend')
            ->label('Extend')
            ->icon(Heroicon::OutlinedClock)
            ->visible(fn (): bool => self::admin() !== null)
            ->schema([
                DateTimePicker::make('until')->label('New deadline (Pakistan time)')->timezone($tz)->seconds(false)->required(),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (Order $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    app(ExtendTransferHold::class)->handle($record, $admin, CarbonImmutable::parse((string) $data['until'], 'UTC'), (string) ($data['reason'] ?? ''));
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not extended')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Deadline extended')->send();
            });
    }

    /** The transfer waiting for review, if any. */
    private static function transfer(Order $order): ?BankTransfer
    {
        return $order->bankTransfers->sortByDesc('id')->first(fn (BankTransfer $t): bool => $t->status === BankTransferStatus::Submitted);
    }

    private static function reviewable(Order $order): bool
    {
        return self::admin() !== null && $order->status === OrderStatus::AwaitingVerification && self::transfer($order) !== null;
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('review', BankTransfer::class) ? $user : null;
    }

    /** @return Builder<Order> */
    public static function waiting(): Builder
    {
        return Order::query()->where('status', OrderStatus::AwaitingVerification);
    }
}
