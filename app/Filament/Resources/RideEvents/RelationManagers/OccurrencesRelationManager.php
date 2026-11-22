<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEvents\RelationManagers;

use App\Actions\Events\CancelEventOccurrence;
use App\Actions\Events\SaveEventOccurrence;
use App\Enums\LessonClosure;
use App\Enums\SlotStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * Dates of an event (decided 2026-11-22): each a slot with its own start,
 * end (one day or several) and capacity, and whether it closes the
 * regular lesson slots. Add, edit, close, reopen or cancel; every change
 * goes through SaveEventOccurrence / CancelEventOccurrence (audit log,
 * lesson closures, page cache). Cancelling a date with bookings lets each
 * customer choose a full refund or another date.
 */
class OccurrencesRelationManager extends RelationManager
{
    protected static string $relationship = 'occurrences';

    protected static ?string $title = 'Dates';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        $tz = (string) config('app.display_timezone');

        return $schema
            ->columns(2)
            ->components([
                DateTimePicker::make('starts_at')->label('Starts (Pakistan time)')->required()->seconds(false)->timezone($tz),
                DateTimePicker::make('ends_at')->label('Ends (Pakistan time)')->required()->seconds(false)->timezone($tz)->after('starts_at')
                    ->helperText('Later the same day, or on a later day for a multi-day event.'),
                TextInput::make('capacity')->label('Places')->required()->integer()->minValue(1)->maxValue(SaveEventOccurrence::MAX_CAPACITY)->default(10),
                Select::make('lesson_closure')->label('Regular lessons')->options(LessonClosure::class)->required()->default(LessonClosure::None->value)
                    ->helperText('Closing never cancels lesson bookings: any clashes are listed on this page to move.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withSeatCounts())
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')->label('Date')->state(fn (Slot $record): string => $record->dateRangeLabel()),
                TextColumn::make('status')->badge(),
                TextColumn::make('seats')->label('Booked')->state(fn (Slot $record): string => $record->seatsBooked().' booked, '.$record->seatsHeld().' held / '.$record->capacity),
                TextColumn::make('lesson_closure')->label('Lessons')->badge(),
            ])
            ->headerActions([
                Action::make('addDate')->label('Add date')->icon(Heroicon::OutlinedPlus)
                    ->schema(fn (Schema $schema): Schema => $this->form($schema))
                    ->action(function (array $data): void {
                        $event = $this->getOwnerRecord();
                        assert($event instanceof RideEvent);
                        $this->save(fn () => app(SaveEventOccurrence::class)->create($event, CarbonImmutable::parse($data['starts_at'], 'UTC'), CarbonImmutable::parse($data['ends_at'], 'UTC'), (int) $data['capacity'], self::closure($data['lesson_closure']), self::actor()));
                    }),
            ])
            ->recordActions([
                Action::make('editDate')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Slot $record): bool => $record->status !== SlotStatus::Cancelled)
                    ->fillForm(fn (Slot $record): array => ['starts_at' => $record->starts_at, 'ends_at' => $record->ends_at, 'capacity' => $record->capacity, 'lesson_closure' => $record->lesson_closure->value])
                    ->schema(fn (Schema $schema): Schema => $this->form($schema))
                    ->action(fn (Slot $record, array $data) => $this->save(fn () => app(SaveEventOccurrence::class)->update($record, CarbonImmutable::parse($data['starts_at'], 'UTC'), CarbonImmutable::parse($data['ends_at'], 'UTC'), (int) $data['capacity'], self::closure($data['lesson_closure']), self::actor()))),
                $this->statusAction(SlotStatus::Closed, 'Close', Heroicon::OutlinedPauseCircle, 'No new bookings for this date. Bookings already made stay.'),
                $this->statusAction(SlotStatus::Open, 'Reopen', Heroicon::OutlinedPlayCircle, 'Bookings open again for this date.'),
                $this->statusAction(SlotStatus::Cancelled, 'Cancel', Heroicon::OutlinedXCircle, 'The date won’t run. Everyone booked is emailed and chooses a full refund (deposit included) or another date. Unpaid orders are cancelled. This can’t be undone.'),
            ]);
    }

    private function statusAction(SlotStatus $to, string $label, Heroicon $icon, string $description): Action
    {
        $action = Action::make('date_'.$to->value)
            ->label($label)
            ->icon($icon)
            ->color($to === SlotStatus::Cancelled ? 'danger' : 'gray')
            ->visible(fn (Slot $record): bool => $record->status->canTransitionTo($to) && $record->ends_at->isFuture())
            ->requiresConfirmation()
            ->modalDescription($description)
            ->action(function (Slot $record, array $data) use ($to): void {
                try {
                    app(CancelEventOccurrence::class)->handle($record, $to, self::actor(), is_string($data['reason'] ?? null) ? $data['reason'] : null);
                    Notification::make()->success()->title('Date '.$to->label())->send();
                } catch (InvalidStatusTransition $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });

        return $to === SlotStatus::Cancelled
            ? $action->schema([Textarea::make('reason')->label('Reason (shown to customers)')->required()->maxLength(500)])
            : $action;
    }

    private function save(callable $write): void
    {
        try {
            $write();
            Notification::make()->success()->title('Saved')->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    private static function closure(mixed $value): LessonClosure
    {
        return $value instanceof LessonClosure ? $value : LessonClosure::from((string) $value);
    }

    private static function actor(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
