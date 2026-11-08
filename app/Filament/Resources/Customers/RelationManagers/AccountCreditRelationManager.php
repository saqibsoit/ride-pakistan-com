<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Actions\Credit\AccountCredit;
use App\Exceptions\CreditRejected;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer's PKR account credit (milestone 10e, admins only): balance,
 * history and an "Adjust" action (reason required, never below zero).
 */
class AccountCreditRelationManager extends RelationManager
{
    protected static string $relationship = 'accountCredit';

    protected static ?string $title = 'Account credit';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user() instanceof User && auth()->user()->isAdmin();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');
        /** @var User $customer */
        $customer = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('actor'))
            ->defaultSort('id', 'desc')
            ->description(fn (): string => 'Balance: '.Money::format(app(AccountCredit::class)->balance($customer)))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M Y H:i', $tz),
                TextColumn::make('delta_minor')->label('Change')
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').Money::format($state)),
                TextColumn::make('reason')->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('note')->placeholder('—')->wrap(),
                TextColumn::make('actor.name')->label('By')->placeholder('System'),
            ])
            ->headerActions([
                Action::make('adjust')
                    ->label('Adjust credit')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->schema([
                        TextInput::make('amount_pkr')->label('Amount (PKR)')->integer()->required()
                            ->helperText('Positive adds credit, negative removes it.'),
                        Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
                    ])
                    ->action(function (array $data) use ($customer): void {
                        $admin = auth()->user();

                        if (! $admin instanceof User) {
                            return;
                        }

                        try {
                            app(AccountCredit::class)->adjust($customer, $admin, (int) $data['amount_pkr'] * Money::MINOR_PER_MAJOR, (string) $data['reason']);
                        } catch (CreditRejected $e) {
                            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Account credit changed')->send();
                    }),
            ]);
    }
}
