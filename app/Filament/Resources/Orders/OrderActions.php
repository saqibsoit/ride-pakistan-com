<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Actions\Notifications\ResendNotification;
use App\Actions\Payments\FlagOrderForReview;
use App\Actions\Payments\RefundOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Admin order tools: refund a card order through Stripe (RefundOrder) and
 * clear a payment alert once it has been looked at.
 */
final class OrderActions
{
    public static function refund(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (Order $record): bool => self::admin($record) !== null
                && $record->payment_method === PaymentMethod::Card
                && $record->stripe_payment_intent_id !== null
                && in_array($record->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true))
            ->modalDescription(fn (Order $record): string => 'Refunds go back to the card through Stripe. Not yet refunded: '
                .Money::format($record->amount_minor - $record->amount_refunded_minor, $record->currency).'.')
            ->schema(fn (Order $record): array => [
                Toggle::make('full')
                    ->label('Refund everything not yet refunded')
                    ->helperText('Cancels every upcoming booking of this order and voids its unused credits.')
                    ->default(true)
                    ->live(),
                TextInput::make('amount')
                    ->label('Amount (PKR)')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(intdiv($record->amount_minor - $record->amount_refunded_minor, Money::MINOR_PER_MAJOR))
                    ->required(fn (Get $get): bool => ! $get('full'))
                    ->visible(fn (Get $get): bool => ! $get('full')),
                CheckboxList::make('cancel_bookings')
                    ->label('Cancel these bookings')
                    ->options(fn (): array => RefundOrder::upcomingBookings($record)->get()
                        ->mapWithKeys(fn (Booking $b): array => [$b->id => $b->slot->label().': '.$b->rider->name.' ('.$b->ticket_code.')'])
                        ->all())
                    ->visible(fn (Get $get): bool => ! $get('full') && RefundOrder::upcomingBookings($record)->exists()),
                TextInput::make('remove_credits')
                    ->label('Unused credits to remove')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(RefundOrder::creditsLeft($record))
                    ->default(0)
                    ->helperText(RefundOrder::creditsLeft($record).' unused on this order\'s pack.')
                    ->visible(fn (Get $get): bool => ! $get('full') && RefundOrder::creditsLeft($record) > 0),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->requiresConfirmation()
            ->action(function (Order $record, array $data): void {
                $admin = self::admin($record);

                if ($admin === null) {
                    return;
                }

                $full = (bool) ($data['full'] ?? true);
                $amount = $full
                    ? $record->amount_minor - $record->amount_refunded_minor
                    : (int) ($data['amount'] ?? 0) * Money::MINOR_PER_MAJOR;

                try {
                    app(RefundOrder::class)->handle(
                        $record,
                        $admin,
                        $amount,
                        (string) ($data['reason'] ?? ''),
                        $full ? [] : array_values(array_map('intval', (array) ($data['cancel_bookings'] ?? []))),
                        $full ? 0 : (int) ($data['remove_credits'] ?? 0),
                    );
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not refunded')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Refunded '.Money::format($amount, $record->currency))->send();
            });
    }

    public static function clearReview(): Action
    {
        return Action::make('clear_review')
            ->label('Mark reviewed')
            ->icon(Heroicon::OutlinedCheck)
            ->visible(fn (Order $record): bool => self::admin($record) !== null && isset($record->meta['needs_review']))
            ->requiresConfirmation()
            ->modalDescription(fn (Order $record): string => (string) ($record->meta['needs_review'] ?? ''))
            ->action(function (Order $record): void {
                $admin = self::admin($record);

                if ($admin === null) {
                    return;
                }

                app(FlagOrderForReview::class)->clear($record);

                activity('payments')->performedOn($record)->causedBy($admin)->event('review_cleared')->log('Payment alert on '.$record->reference.' reviewed');

                Notification::make()->success()->title('Marked as reviewed')->send();
            });
    }

    public static function resend(): Action
    {
        return Action::make('resend_email')
            ->label('Resend email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (Order $record): bool => self::admin($record) !== null && ResendNotification::optionsForOrder($record) !== [])
            ->schema(fn (Order $record): array => [
                Select::make('type')->label('Email')->options(ResendNotification::optionsForOrder($record))->required(),
            ])
            ->action(function (Order $record, array $data): void {
                $admin = self::admin($record);

                if ($admin === null) {
                    return;
                }

                try {
                    app(ResendNotification::class)->forOrder($record, (string) $data['type'], $admin);
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Email queued for '.$record->user->email)->send();
            });
    }

    private static function admin(Order $order): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('refund', $order) ? $user : null;
    }
}
