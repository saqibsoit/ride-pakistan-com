<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vouchers\Pages;

use App\Actions\Vouchers\IssueManualVoucher;
use App\Exceptions\VoucherRejected;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\User;
use App\Models\Voucher;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListVouchers extends ListRecords
{
    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue voucher')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => auth()->user()?->can('issue', Voucher::class) === true)
                ->modalDescription('For a voucher not bought on the site: a goodwill gesture, a prize, a payment taken elsewhere. It is emailed to the recipient straight away.')
                ->schema([
                    TextInput::make('amount_pkr')->label('Amount (PKR)')->integer()->minValue(1)->required(),
                    TextInput::make('label')->label('Label (optional)')->maxLength(120)->helperText('Shown on the voucher, e.g. "Single session".'),
                    TextInput::make('name')->label('Recipient name')->required()->maxLength(120),
                    TextInput::make('email')->label('Recipient email')->email()->required()->maxLength(190),
                    Textarea::make('reason')->label('Reason (internal)')->required()->maxLength(500)->rows(2),
                ])
                ->action(function (array $data): void {
                    $admin = auth()->user();

                    if (! $admin instanceof User) {
                        return;
                    }

                    try {
                        $voucher = app(IssueManualVoucher::class)->handle(
                            $admin,
                            (int) $data['amount_pkr'] * 100,
                            (string) $data['name'],
                            (string) $data['email'],
                            (string) $data['reason'],
                            isset($data['label']) ? (string) $data['label'] : null,
                        );
                    } catch (VoucherRejected $e) {
                        Notification::make()->danger()->title('Not issued')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Voucher '.$voucher->formattedCode().' issued and emailed')->send();
                }),
        ];
    }
}
