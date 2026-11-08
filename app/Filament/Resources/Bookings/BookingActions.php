<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CreateManualBooking;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Notifications\ResendNotification;
use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Admin booking tools (Bookings list and the slot's bookings tab). Every
 * change goes through the booking actions, with the same seat rules and
 * slot lock as customer bookings, and is logged. Visible to admins only
 * (BookingPolicy::manage); staff check-in arrives in milestone 8.
 */
final class BookingActions
{
    /**
     * @return list<Action>
     */
    public static function row(): array
    {
        return [self::attended(), self::noShow(), self::move(), self::cancel(), self::resend()];
    }

    public static function attended(): Action
    {
        return Action::make('attended')
            ->label('Attended')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Booking $record): bool => self::canMark($record, BookingStatus::Attended))
            ->action(fn (Booking $record) => self::mark($record, BookingStatus::Attended));
    }

    public static function noShow(): Action
    {
        return Action::make('no_show')
            ->label('No-show')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->visible(fn (Booking $record): bool => self::canMark($record, BookingStatus::NoShow))
            ->action(fn (Booking $record) => self::mark($record, BookingStatus::NoShow));
    }

    public static function move(): Action
    {
        return Action::make('move')
            ->label('Move')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (Booking $record): bool => self::admin() !== null && $record->status === BookingStatus::Confirmed)
            ->modalDescription(fn (Booking $record): string => $record->is_private
                ? 'A private session moves as a group, to a slot nobody else has booked.'
                : 'The ticket code stays the same.')
            ->schema([
                Select::make('slot_id')
                    ->label('New slot')
                    ->options(fn (Booking $record): array => self::slotOptions($record->slot_id))
                    ->searchable()
                    ->required(),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (Booking $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    $moved = app(RescheduleBooking::class)->handle($record, (int) $data['slot_id'], $admin, (string) $data['reason'], asAdmin: true);
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Booking not moved')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($moved->count() === 1 ? 'Booking moved' : $moved->count().' bookings moved')->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel_booking')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Booking $record): bool => self::admin() !== null && $record->status === BookingStatus::Confirmed)
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
                Toggle::make('return_credit')
                    ->label('Give the credit back')
                    ->helperText('Off follows the rule: back before the cutoff, used after it.')
                    ->visible(fn (Booking $record): bool => $record->usesCredit()),
            ])
            ->action(function (Booking $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    $returned = app(CancelBooking::class)->handle(
                        $record,
                        $admin,
                        (string) $data['reason'],
                        asAdmin: true,
                        returnCredit: ($data['return_credit'] ?? false) ? true : null,
                    );
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Booking not cancelled')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Booking cancelled')->body($returned ? 'The credit went back to the pack.' : null)->send();
            });
    }

    public static function resend(): Action
    {
        return Action::make('resend_email')
            ->label('Resend')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (Booking $record): bool => self::admin() !== null && ResendNotification::optionsForBooking($record) !== [])
            ->schema(fn (Booking $record): array => [
                Select::make('type')->label('Email')->options(ResendNotification::optionsForBooking($record))->required(),
            ])
            ->action(function (Booking $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    app(ResendNotification::class)->forBooking($record, (string) $data['type'], $admin);
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Email queued')->send();
            });
    }

    /** Header action: book a customer's rider with a credit or at no charge. */
    public static function manual(?int $slotId = null): Action
    {
        return Action::make('manual_booking')
            ->label('Manual booking')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn (): bool => self::admin() !== null)
            ->schema([
                Select::make('rider_id')
                    ->label('Rider')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Rider::query()
                        ->with('user')
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($q) => $q->where('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'))
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (Rider $rider): array => [$rider->id => $rider->name.' ('.$rider->user->email.')'])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? Rider::query()->whereKey((int) $value)->value('name') : null)
                    ->live()
                    ->required(),
                Select::make('slot_id')
                    ->label('Slot')
                    ->options(fn (): array => self::slotOptions(null))
                    ->default($slotId)
                    ->searchable()
                    ->required(),
                Select::make('credit_pack_id')
                    ->label('Pay with')
                    ->placeholder('No charge (give a reason)')
                    ->options(fn (Get $get): array => self::packOptions($get('rider_id'))),
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('Required for a no-charge booking.')
                    ->requiredWithout('credit_pack_id')
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $admin = self::admin();
                $riderId = $data['rider_id'] ?? null;
                $rider = is_numeric($riderId) ? Rider::query()->whereKey((int) $riderId)->first() : null;

                if ($admin === null || $rider === null) {
                    return;
                }

                $packId = $data['credit_pack_id'] ?? null;
                $pack = is_numeric($packId) ? CreditPack::query()->where('user_id', $rider->user_id)->whereKey((int) $packId)->first() : null;

                try {
                    app(CreateManualBooking::class)->handle($admin, $rider, (int) $data['slot_id'], $pack, isset($data['reason']) ? (string) $data['reason'] : null);
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not booked')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Booked '.$rider->name)->send();
            });
    }

    /**
     * Upcoming open slots, labelled with seats left.
     *
     * @return array<int, string>
     */
    private static function slotOptions(?int $except): array
    {
        return Slot::query()
            ->withSeatCounts()
            ->where('slots.status', SlotStatus::Open)
            ->where('slots.starts_at', '>', now())
            ->when($except !== null, fn ($q) => $q->where('slots.id', '!=', $except))
            ->orderBy('slots.starts_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (Slot $slot): array => [$slot->id => $slot->label().' · '.$slot->seatsFree().' free'])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function packOptions(mixed $riderId): array
    {
        $rider = is_numeric($riderId) ? Rider::query()->whereKey((int) $riderId)->first() : null;

        if ($rider === null) {
            return [];
        }

        return CreditPack::query()
            ->where('user_id', $rider->user_id)
            ->where('status', CreditPackStatus::Active)
            ->where('expires_at', '>', now())
            ->with('order.product')
            ->get()
            ->mapWithKeys(fn (CreditPack $pack): array => [$pack->id => $pack->order->productName().' · '.$pack->creditsRemaining().' left, until '.$pack->expires_at->setTimezone((string) config('app.display_timezone'))->format('j M')])
            ->all();
    }

    private static function canMark(Booking $booking, BookingStatus $to): bool
    {
        return self::admin() !== null
            && $booking->status->canTransitionTo($to)
            && ! $booking->slot->starts_at->isFuture();
    }

    private static function mark(Booking $booking, BookingStatus $to): void
    {
        try {
            app(ChangeBookingStatus::class)->handle($booking, $to, self::admin());
        } catch (BookingRejected|InvalidStatusTransition $e) {
            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Marked '.$to->label())->send();
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('manage', Booking::class) ? $user : null;
    }
}
