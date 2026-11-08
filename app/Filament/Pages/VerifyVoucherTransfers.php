<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Vouchers\ReviewVoucherTransfer;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\VoucherRejected;
use App\Http\Controllers\VoucherTransferProofController;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Support\Money;
use App\Support\TransferProofs;
use BackedEnum;
use Filament\Actions\Action;
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
use UnitEnum;

/**
 * Gift voucher bank transfers to verify (milestone 10e), oldest upload
 * first. Admins only, like order transfers: approve (IssueVoucher) or
 * reject with a reason. Uploaded transfers never expire, so there is no
 * deadline to extend. Proofs open through a short-lived signed URL.
 */
class VerifyVoucherTransfers extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.verify-transfers';

    protected static string|UnitEnum|null $navigationGroup = 'Sales & promotions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $title = 'Verify voucher transfers';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('review', VoucherOrder::class);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = VoucherOrder::query()->where('status', VoucherOrderStatus::AwaitingVerification)->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->query(VoucherOrder::query()
                ->whereIn('status', [VoucherOrderStatus::AwaitingVerification, VoucherOrderStatus::AwaitingTransfer])
                ->with('user'))
            ->defaultSort('submitted_at')
            ->columns([
                TextColumn::make('reference')->searchable()->copyable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('user.name')->label('Customer')->searchable()
                    ->description(fn (VoucherOrder $record): string => (string) $record->user->email),
                TextColumn::make('amount_minor')->label('Expected')
                    ->formatStateUsing(fn (VoucherOrder $record): string => Money::format($record->amount_minor, $record->currency)),
                TextColumn::make('transaction_ref')->label('Transaction ID')->placeholder('—'),
                TextColumn::make('recipient_name')->label('For'),
                TextColumn::make('submitted_at')->label('Uploaded')->sortable()
                    ->dateTime('j M H:i', timezone: $tz)
                    ->since()
                    ->placeholder('Not yet'),
                TextColumn::make('expires_at')->label('Upload by')
                    ->dateTime('D j M H:i', timezone: $tz)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        VoucherOrderStatus::AwaitingVerification->value => 'Proof uploaded',
                        VoucherOrderStatus::AwaitingTransfer->value => 'Waiting for the customer',
                    ])
                    ->default(VoucherOrderStatus::AwaitingVerification->value),
            ])
            ->recordActions([
                $this->proofAction(),
                $this->approveAction(),
                $this->rejectAction(),
            ])
            ->emptyStateHeading('No voucher transfers to verify')
            ->emptyStateDescription('New uploads appear here, oldest first.');
    }

    private function proofAction(): Action
    {
        return Action::make('view_proof')
            ->label('Proof')
            ->icon(Heroicon::OutlinedPhoto)
            ->visible(fn (VoucherOrder $record): bool => $record->submitted_at !== null)
            ->modalHeading(fn (VoucherOrder $record): string => 'Transfer proof: '.$record->reference)
            ->modalContent(fn (VoucherOrder $record): View => view('filament.transfers.proof', [
                'order' => $record,
                'transfer' => $record,
                'url' => $record->screenshot_path === null ? null : VoucherTransferProofController::signedUrl($record),
                'pdf' => $record->screenshot_path !== null && TransferProofs::isPdf($record->screenshot_path),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (VoucherOrder $record): bool => self::admin() !== null && $record->status === VoucherOrderStatus::AwaitingVerification)
            ->requiresConfirmation()
            ->modalDescription(fn (VoucherOrder $record): string => 'Only approve once '.Money::format($record->amount_minor, $record->currency)
                .' with reference '.$record->reference.' is in the account. This issues the voucher and emails it to '.$record->recipient_name.'.')
            ->action(function (VoucherOrder $record): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    app(ReviewVoucherTransfer::class)->approve($record, $admin);
                } catch (VoucherRejected $e) {
                    Notification::make()->danger()->title('Not approved')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Approved: voucher issued for '.$record->reference)->send();
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (VoucherOrder $record): bool => self::admin() !== null && $record->status === VoucherOrderStatus::AwaitingVerification)
            ->schema([
                Textarea::make('reason')->label('Reason (sent to the customer)')->required()->maxLength(500)->rows(3),
            ])
            ->action(function (VoucherOrder $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    app(ReviewVoucherTransfer::class)->reject($record, $admin, (string) ($data['reason'] ?? ''));
                } catch (VoucherRejected $e) {
                    Notification::make()->danger()->title('Not rejected')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Rejected: the customer has been emailed')->send();
            });
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('review', VoucherOrder::class) ? $user : null;
    }
}
