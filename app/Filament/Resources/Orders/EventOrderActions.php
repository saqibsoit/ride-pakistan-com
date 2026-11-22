<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Actions\Events\CancelEventBooking;
use App\Actions\Events\MarkBalancePaid;
use App\Actions\Events\RespondToCancelledEventDate;
use App\Enums\BalanceMethod;
use App\Exceptions\BookingRejected;
use App\Models\Order;
use App\Models\Slot;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Admin tools for event orders (decided 2026-11-22): record the balance,
 * cancel for the customer under the event's rules, and answer for a
 * customer after we cancelled a date (full refund or another date).
 * Admins only, like every money action.
 */
final class EventOrderActions
{
    public static function markBalancePaid(): Action
    {
        return Action::make('markBalancePaid')
            ->label('Mark balance paid')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Order $record): bool => self::admin($record) !== null && ($record->balance?->isDue() ?? false))
            ->modalDescription(fn (Order $record): string => 'Balance due: '.Money::format($record->balance->amount_minor ?? 0, $record->currency).'. The customer gets a receipt.')
            ->schema([
                Select::make('method')->label('Paid by')->required()
                    ->options(collect(BalanceMethod::manual())->mapWithKeys(fn (BalanceMethod $m): array => [$m->value => $m->getLabel()])->all()),
                TextInput::make('reference')->label('Reference (optional)')->maxLength(100)
                    ->helperText('A bank transaction ID or card slip number.'),
            ])
            ->action(function (Order $record, array $data): void {
                $admin = self::admin($record);

                if ($admin === null || $record->balance === null) {
                    return;
                }

                try {
                    app(MarkBalancePaid::class)->handle($record->balance, $admin, BalanceMethod::from((string) $data['method']), is_string($data['reference'] ?? null) ? $data['reference'] : null);
                    Notification::make()->success()->title('Balance marked paid')->send();
                } catch (AuthorizationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function cancelForCustomer(): Action
    {
        return Action::make('cancelEventBooking')
            ->label('Cancel (event rules)')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record): bool => ($admin = self::admin($record)) !== null && app(CancelEventBooking::class)->canCancel($record, $admin))
            ->requiresConfirmation()
            ->modalDescription(function (Order $record): string {
                $quote = app(CancelEventBooking::class)->quote($record);

                return 'Cancels everyone on this booking as if the customer cancelled now. Refund by the event’s rules: '.Money::format($quote['refund'], $record->currency)
                    .($quote['before_cutoff'] ? ' (before the cutoff)' : ' (after the cutoff)').'. Deposits are never refunded. Card money goes back automatically; bank or cash money is flagged for you to pay back.';
            })
            ->schema([Textarea::make('reason')->required()->maxLength(500)])
            ->action(fn (Order $record, array $data) => self::run(fn (User $admin) => app(CancelEventBooking::class)->handle($record, $admin, (string) $data['reason']), $record, 'Booking cancelled'));
    }

    public static function refundCancelledDate(): Action
    {
        return Action::make('refundCancelledDate')
            ->label('Full refund (date cancelled)')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (Order $record): bool => self::admin($record) !== null && RespondToCancelledEventDate::pending($record))
            ->requiresConfirmation()
            ->modalDescription('We cancelled this date: everyone is cancelled and everything paid comes back, deposit included. Card money goes back automatically; bank or cash money is flagged for you to pay back.')
            ->action(fn (Order $record) => self::run(fn (User $admin) => app(RespondToCancelledEventDate::class)->refund($record, $admin), $record, 'Refunded'));
    }

    public static function moveCancelledDate(): Action
    {
        return Action::make('moveCancelledDate')
            ->label('Move to another date')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (Order $record): bool => self::admin($record) !== null && RespondToCancelledEventDate::pending($record))
            ->schema(fn (Order $record): array => [
                Select::make('slot')->label('New date')->required()
                    ->options(RespondToCancelledEventDate::alternatives($record)->mapWithKeys(fn (Slot $slot): array => [$slot->id => $slot->dateRangeLabel().' · '.$slot->seatsFree().' free'])->all()),
            ])
            ->action(fn (Order $record, array $data) => self::run(fn (User $admin) => app(RespondToCancelledEventDate::class)->move($record, (int) $data['slot'], $admin), $record, 'Moved'));
    }

    private static function run(callable $work, Order $order, string $done): void
    {
        $admin = self::admin($order);

        if ($admin === null) {
            return;
        }

        try {
            $work($admin);
            Notification::make()->success()->title($done)->send();
        } catch (BookingRejected $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    private static function admin(Order $order): ?User
    {
        $user = auth()->user();

        return $order->isEvent() && $user instanceof User && $user->can('refund', $order) ? $user : null;
    }
}
