<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Actions\Booking\ExtendCreditPack;
use App\Exceptions\BookingRejected;
use App\Models\CreditPack;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A customer's package credits. Admins can extend a pack's expiry with a
 * reason (ExtendCreditPack: ledger "adjusted" entry and audit log).
 */
class CreditPacksRelationManager extends RelationManager
{
    protected static string $relationship = 'creditPacks';

    protected static ?string $title = 'Credits';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('order.product'))
            ->defaultSort('expires_at', 'desc')
            ->columns([
                TextColumn::make('order.product.name')->label('Package'),
                TextColumn::make('credits')
                    ->label('Credits left')
                    ->state(fn (CreditPack $record): string => $record->creditsRemaining().' of '.$record->credits_total),
                TextColumn::make('status')->badge(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->formatStateUsing(fn (CreditPack $record): string => $record->expires_at->setTimezone($tz)->format('j M Y')),
                TextColumn::make('order.reference')->label('Order'),
            ])
            ->recordActions([
                Action::make('extend')
                    ->label('Extend')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->visible(fn (CreditPack $record): bool => $this->admin()?->can('extend', $record) ?? false)
                    ->schema([
                        DatePicker::make('expires_on')
                            ->label('New expiry date')
                            ->helperText('Credits can be used for sessions starting before the end of this day (Pakistan time).')
                            ->native(false)
                            ->minDate(now($tz)->addDay()->startOfDay())
                            ->required(),
                        Textarea::make('reason')->label('Reason')->required()->maxLength(400)->rows(2),
                    ])
                    ->action(function (CreditPack $record, array $data) use ($tz): void {
                        $admin = $this->admin();

                        if ($admin === null) {
                            return;
                        }

                        $expiry = CarbonImmutable::parse((string) $data['expires_on'], $tz)->endOfDay()->utc();

                        try {
                            app(ExtendCreditPack::class)->handle($record, $expiry, $admin, (string) $data['reason']);
                        } catch (BookingRejected $e) {
                            Notification::make()->danger()->title('Not extended')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Pack extended')->send();
                    }),
            ]);
    }

    private function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
