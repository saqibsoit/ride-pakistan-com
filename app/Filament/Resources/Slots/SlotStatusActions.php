<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots;

use App\Actions\Slots\AssignSlotTrainer;
use App\Actions\Slots\ChangeSlotStatus;
use App\Actions\Slots\CreateManualSlot;
use App\Actions\Slots\UpdateManualSlot;
use App\Enums\SlotStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\SlotNotCreated;
use App\Exceptions\SlotNotUpdated;
use App\Filament\Support\TrainerOptions;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
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
        return [self::runSheet(), self::assignTrainer(), self::editManual(), self::close(), self::reopen(), self::cancel()];
    }

    /** The mobile run sheet for this session (milestone 10b). */
    public static function runSheet(): Action
    {
        return Action::make('runSheet')
            ->label('Run sheet')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->visible(fn (Slot $record): bool => self::user()?->can('viewRunSheet', $record) === true)
            ->url(fn (Slot $record): string => route('team.sessions.show', $record))
            ->openUrlInNewTab();
    }

    /** Who runs this session (AssignSlotTrainer); overrides the rule's trainer. */
    public static function assignTrainer(): Action
    {
        return Action::make('assignTrainer')
            ->label('Trainer')
            ->icon(Heroicon::OutlinedUser)
            ->visible(fn (Slot $record): bool => self::user()?->can('assignTrainer', $record) === true && $record->status !== SlotStatus::Cancelled)
            ->modalHeading('Who runs this session?')
            ->modalDescription('Only this trainer sees the session and its riders on the run sheet. A later change to the rule\'s trainer leaves this choice alone.')
            ->fillForm(fn (Slot $record): array => ['trainer_id' => $record->trainer_id])
            ->schema([
                Select::make('trainer_id')->label('Trainer')
                    ->options(fn (): array => TrainerOptions::all())
                    ->placeholder('Nobody'),
            ])
            ->action(function (Slot $record, array $data, AssignSlotTrainer $assign): void {
                $user = self::user();
                abort_unless($user?->can('assignTrainer', $record) === true, 403);

                $trainer = filled($data['trainer_id'] ?? null) ? User::query()->find((int) $data['trainer_id']) : null;
                $assign->handle($record, $trainer, $user);

                Notification::make()->success()->title($trainer === null ? 'No trainer set' : 'Trainer: '.$trainer->displayName())->send();
            });
    }

    /** Edit a manual slot (UpdateManualSlot): time while empty, capacity, private-only. */
    public static function editManual(): Action
    {
        return Action::make('editManual')
            ->label('Edit slot')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (Slot $record): bool => self::user()?->can('update', $record) === true && $record->status === SlotStatus::Open)
            ->modalHeading('Edit manual slot')
            ->modalDescription('The time can change only while nobody has booked or is paying. Capacity can’t go below the seats taken.')
            ->fillForm(fn (Slot $record): array => [
                'date' => $record->localStartsAt()->toDateString(),
                'start_time' => $record->localStartsAt()->format('H:i'),
                'end_time' => $record->localEndsAt()->format('H:i'),
                'capacity' => $record->capacity,
                'private_only' => $record->private_only,
            ])
            ->schema([
                DatePicker::make('date')->label('Date (Pakistan time)')->required(),
                TimePicker::make('start_time')->label('Starts (Pakistan time)')->required()->seconds(false),
                TimePicker::make('end_time')->label('Ends (Pakistan time)')->required()->seconds(false)->after('start_time'),
                TextInput::make('capacity')->label('Riders')->required()->integer()
                    ->minValue(CreateManualSlot::MIN_CAPACITY)->maxValue(CreateManualSlot::MAX_CAPACITY),
                Toggle::make('private_only')->label('Private sessions only'),
            ])
            ->action(function (Slot $record, array $data, Action $action, UpdateManualSlot $update): void {
                $user = self::user();
                abort_unless($user?->can('update', $record) === true, 403);

                $tz = Slot::displayTimezone();
                $date = (string) $data['date'];

                try {
                    $update->handle(
                        $record,
                        CarbonImmutable::parse($date.' '.$data['start_time'], $tz),
                        CarbonImmutable::parse($date.' '.$data['end_time'], $tz),
                        (int) $data['capacity'],
                        (bool) ($data['private_only'] ?? false),
                        $user,
                    );
                } catch (SlotNotUpdated|SlotNotCreated $exception) {
                    Notification::make()->danger()->title('Slot not changed')->body($exception->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Slot updated')->body($record->refresh()->label())->send();
            });
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

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
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
