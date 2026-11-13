<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Actions\Discounts\RefundCoveredOrder;
use App\Actions\Guest\RevokeManageLinks;
use App\Actions\Notifications\ResendNotification;
use App\Actions\Payments\FlagOrderForReview;
use App\Actions\Payments\RecordTransferRefund;
use App\Actions\Payments\RefundOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use App\Support\SessionTimes;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
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
                .Money::format($record->amount_minor - $record->amount_refunded_minor, $record->currency).'.'
                .self::discountNote($record))
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

    /**
     * A bank transfer order refunded by the team's own bank transfer
     * (RecordTransferRefund): the admin makes the transfer in online banking,
     * then records it here. Same rules as a card refund.
     */
    public static function transferRefund(): Action
    {
        return Action::make('transfer_refund')
            ->label('Record bank refund')
            ->icon(Heroicon::OutlinedBuildingLibrary)
            ->color('danger')
            ->visible(fn (Order $record): bool => self::admin($record) !== null
                && $record->payment_method === PaymentMethod::BankTransfer
                && in_array($record->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true))
            ->modalDescription(fn (Order $record): string => 'Make the transfer to the customer in online banking first, then record it here. Not yet refunded: '
                .Money::format($record->amount_minor - $record->amount_refunded_minor, $record->currency).'.'
                .self::discountNote($record))
            ->schema(fn (Order $record): array => [
                Toggle::make('full')
                    ->label('Refund everything not yet refunded')
                    ->helperText('Cancels every upcoming booking of this order and voids its unused credits.')
                    ->default(true)
                    ->live(),
                TextInput::make('amount')
                    ->label('Amount transferred (PKR)')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(intdiv($record->amount_minor - $record->amount_refunded_minor, Money::MINOR_PER_MAJOR))
                    ->required(fn (Get $get): bool => ! $get('full'))
                    ->visible(fn (Get $get): bool => ! $get('full')),
                TextInput::make('bank_reference')
                    ->label('Bank reference of your transfer')
                    ->helperText('As it appears on the bank statement, e.g. the transaction ID.')
                    ->required()
                    ->maxLength(RecordTransferRefund::MAX_REFERENCE_LENGTH),
                DatePicker::make('transferred_on')
                    ->label('Transfer date (Pakistan)')
                    ->required()
                    ->native(false)
                    ->default(fn (): string => SessionTimes::now()->toDateString())
                    ->maxDate(fn (): string => SessionTimes::now()->toDateString()),
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
                    app(RecordTransferRefund::class)->handle(
                        $record,
                        $admin,
                        $amount,
                        (string) ($data['bank_reference'] ?? ''),
                        CarbonImmutable::parse((string) $data['transferred_on'], (string) config('app.display_timezone')),
                        (string) ($data['reason'] ?? ''),
                        $full ? [] : array_values(array_map('intval', (array) ($data['cancel_bookings'] ?? []))),
                        $full ? 0 : (int) ($data['remove_credits'] ?? 0),
                    );
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Bank refund of '.Money::format($amount, $record->currency).' recorded')->send();
            });
    }

    /**
     * An order paid entirely by gift voucher and account credit (milestone
     * 10e Part B): nothing to refund by card, so cancel it and give the
     * balances back (RefundCoveredOrder).
     */
    public static function refundCovered(): Action
    {
        return Action::make('refund_covered')
            ->label('Cancel and restore balances')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (Order $record): bool => self::admin($record) !== null
                && $record->payment_method === PaymentMethod::Discounts
                && $record->status === OrderStatus::Paid)
            ->modalDescription('This order was paid by gift voucher and/or account credit. Its upcoming bookings are cancelled, its unused package credits voided, and the voucher balance and account credit given back.')
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->requiresConfirmation()
            ->action(function (Order $record, array $data): void {
                $admin = self::admin($record);

                if ($admin === null) {
                    return;
                }

                try {
                    app(RefundCoveredOrder::class)->handle($record, $admin, (string) ($data['reason'] ?? ''));
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not cancelled')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Cancelled; balances restored')->send();
            });
    }

    /** What a refund does to the order's discounts, and each rider's share after discounts. */
    private static function discountNote(Order $record): string
    {
        $lines = $record->discounts()->get();

        if ($lines->isEmpty()) {
            return '';
        }

        $note = ' This order had discounts ('.$lines->map(fn ($l): string => $l->label.' '.Money::format($l->amount_minor, $record->currency))->implode(', ').').'
            .' Any gift voucher or account credit share comes back in proportion to the amount refunded; promo and referral discounts stay used.';

        $priced = $record->items()->where('unit_price_minor', '>', 0)->get();

        if ($priced->count() > 1 && $record->subtotal_minor > 0) {
            $share = intdiv((int) $priced->first()?->unit_price_minor * $record->amount_minor, $record->subtotal_minor);
            $note .= ' Each rider’s share of what was charged: about '.Money::format($share, $record->currency).'.';
        }

        return $note;
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

    /**
     * Guest checkout: stop every "Manage your booking" link sent for this
     * order (a forwarded email, a shared phone). The customer is emailed a
     * new link. Admins only, reason required, audit-logged.
     */
    public static function revokeManageLinks(): Action
    {
        return Action::make('revoke_manage_links')
            ->label('Revoke manage links')
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('gray')
            ->visible(fn (Order $record): bool => self::admin($record) !== null)
            ->modalDescription('Every link sent so far stops working, also in browsers that opened one. The customer gets a new link by email.')
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->action(function (Order $record, array $data): void {
                $admin = self::admin($record);

                if ($admin === null) {
                    return;
                }

                app(RevokeManageLinks::class)->handle($record, $admin, (string) $data['reason']);

                Notification::make()->success()->title('Links revoked; a new one was emailed to '.$record->user->email)->send();
            });
    }

    private static function admin(Order $order): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('refund', $order) ? $user : null;
    }
}
