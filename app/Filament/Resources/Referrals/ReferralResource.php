<?php

declare(strict_types=1);

namespace App\Filament\Resources\Referrals;

use App\Actions\Leads\LeadReport;
use App\Actions\Referrals\ReviewReferral;
use App\Enums\DiscountKind;
use App\Enums\ReferralStatus;
use App\Exceptions\CreditRejected;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Referrals\Pages\ListReferrals;
use App\Models\OrderDiscount;
use App\Models\Referral;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Referrals (milestone 10e, admins only): who referred whom, status and
 * the reason a referral was rejected (abuse checks or an admin). Rewards
 * are paid automatically after the friend's first attended paid session.
 */
class ReferralResource extends Resource
{
    protected static ?string $model = Referral::class;

    protected static string|UnitEnum|null $navigationGroup = 'Sales & promotions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $navigationLabel = 'Referrals';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['referrer', 'referred']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('referrer.email')->label('Referrer')->searchable()
                    ->url(fn (Referral $record): string => CustomerResource::getUrl('edit', ['record' => $record->referrer_id])),
                TextColumn::make('referred.email')->label('New customer')->searchable()
                    ->url(fn (Referral $record): string => CustomerResource::getUrl('edit', ['record' => $record->referred_id])),
                TextColumn::make('status')->badge(),
                TextColumn::make('rejection_reason')->label('Reason')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('Signed up')->dateTime('j M Y', $tz)->sortable(),
                TextColumn::make('rewarded_at')->label('Rewarded')->dateTime('j M Y', $tz)->placeholder('—'),
                // The friend's welcome discount (milestone 10e Part B): on which paid order.
                TextColumn::make('welcome_discount')
                    ->label('Welcome discount')
                    ->state(fn (Referral $record): ?string => self::welcomeDiscount($record))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(ReferralStatus::class),
            ])
            ->recordActions([
                self::reviewAction('reject', 'Reject', ReferralStatus::Pending, Heroicon::OutlinedNoSymbol, 'danger'),
                self::reviewAction('reinstate', 'Reinstate', ReferralStatus::Rejected, Heroicon::OutlinedArrowUturnLeft, 'gray'),
            ])
            ->emptyStateHeading('No referrals yet');
    }

    private static function reviewAction(string $name, string $label, ReferralStatus $from, Heroicon $icon, string $color): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (Referral $record): bool => $record->status === $from && self::admin() !== null)
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (Referral $record, array $data) use ($name): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    $name === 'reject'
                        ? app(ReviewReferral::class)->reject($record, $admin, (string) $data['reason'])
                        : app(ReviewReferral::class)->reinstate($record, $admin, (string) $data['reason']);
                } catch (CreditRejected $e) {
                    Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($name === 'reject' ? 'Referral rejected' : 'Referral reinstated')->send();
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
            'index' => ListReferrals::route('/'),
        ];
    }

    /** "PKR 1,000 on RP-ABC123" for the paid order that used the welcome discount. */
    private static function welcomeDiscount(Referral $referral): ?string
    {
        $line = OrderDiscount::query()
            ->where('kind', DiscountKind::Referral)
            ->where('source_type', $referral->getMorphClass())
            ->where('source_id', $referral->id)
            ->whereHas('order', fn (Builder $q) => $q->whereIn('status', LeadReport::PAID_STATUSES))
            ->with('order')
            ->first();

        return $line === null ? null : Money::format($line->amount_minor).' on '.$line->order->reference;
    }
}
