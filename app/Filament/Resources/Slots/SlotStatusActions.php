<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots;

use App\Actions\Slots\ChangeSlotStatus;
use App\Enums\SlotStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Slot;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Close, reopen and cancel actions for a slot (table rows and the view
 * page). Visibility follows the slot status and SlotPolicy::changeStatus;
 * the change itself always goes through ChangeSlotStatus.
 */
final class SlotStatusActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [self::close(), self::reopen(), self::cancel()];
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label('Close')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('No new bookings can be made. Existing bookings are kept. You can reopen it later.')
            ->visible(fn (Slot $record): bool => self::allowed($record, SlotStatus::Closed))
            ->action(fn (Slot $record) => self::change($record, SlotStatus::Closed));
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('Reopen')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Slot $record): bool => self::allowed($record, SlotStatus::Open))
            ->action(fn (Slot $record) => self::change($record, SlotStatus::Open));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancel slot')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Slot $record): string => 'Cancel '.$record->label().'?')
            ->modalDescription(fn (Slot $record): string => sprintf(
                'This cannot be undone. %d booked %s will be told the session is cancelled (notifications arrive in milestone 8).',
                $record->seatsBooked(),
                $record->seatsBooked() === 1 ? 'rider' : 'riders',
            ))
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('Kept in the audit log and used in the message to riders.')
                    ->required()
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->visible(fn (Slot $record): bool => self::allowed($record, SlotStatus::Cancelled))
            ->action(function (Slot $record, array $data): void {
                $reason = $data['reason'] ?? null;

                self::change($record, SlotStatus::Cancelled, is_string($reason) ? $reason : null);
            });
    }

    private static function allowed(Slot $slot, SlotStatus $to): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('changeStatus', $slot)
            && $slot->status->canTransitionTo($to);
    }

    private static function change(Slot $slot, SlotStatus $to, ?string $reason = null): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can('changeStatus', $slot), 403);

        try {
            app(ChangeSlotStatus::class)->handle($slot, $to, $user, $reason);
        } catch (InvalidStatusTransition $exception) {
            Notification::make()->danger()->title('Slot not changed')->body($exception->getMessage())->send();

            return;
        }

        $slot->refresh();

        Notification::make()->success()->title('Slot '.$to->label())->send();
    }
}
