<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Slots\BulkSchedule\ApplyBulkSchedule;
use App\Actions\Slots\BulkSchedule\BulkScheduleCriteria;
use App\Actions\Slots\BulkSchedule\BulkSchedulePreview;
use App\Actions\Slots\BulkSchedule\PreviewBulkSchedule;
use App\Actions\Slots\CreateManualSlot;
use App\Enums\BulkScheduleOperation;
use App\Filament\Support\TrainerOptions;
use App\Models\Slot;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use UnitEnum;

/**
 * Bulk schedule editor (prompt 15A, admins only): pick dates, weekdays and
 * times, preview what would be created, changed, closed or cancelled, then
 * apply. Nothing is saved until "Apply". The preview is checked again when
 * applying (ApplyBulkSchedule); rows that changed in between are reported.
 *
 * @property-read Schema $form
 */
class BulkSchedule extends Page
{
    protected string $view = 'filament.pages.bulk-schedule';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static ?string $title = 'Bulk schedule';

    protected static ?int $navigationSort = 21;

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * The form state the preview was made from; applying uses exactly this.
     *
     * @var array<string, mixed>|null
     */
    public ?array $previewedWith = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $previewRows = null;

    /** @var array<string, mixed>|null */
    public ?array $summary = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('bulkSchedule', Slot::class);
    }

    public function mount(): void
    {
        $tz = Slot::displayTimezone();

        $this->form->fill([
            'operation' => BulkScheduleOperation::Update->value,
            'from' => CarbonImmutable::now($tz)->toDateString(),
            'until' => CarbonImmutable::now($tz)->addWeeks(4)->toDateString(),
            'days' => [6, 0],
            'capacity' => null,
            'type' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $isCreate = fn (Get $get): bool => self::operation($get) === BulkScheduleOperation::Create;
        $changes = fn (Get $get): bool => in_array(self::operation($get), [BulkScheduleOperation::Create, BulkScheduleOperation::Update], true);

        return $schema->components([
            Section::make('What to do')->columns(2)->schema([
                Select::make('operation')->label('Action')->options(BulkScheduleOperation::class)->required()->live()
                    ->afterStateUpdated(fn () => $this->resetPreview()),
                Select::make('type')->label(fn (Get $get): string => $isCreate($get) ? 'Slot type' : 'Only these slots')
                    ->options(fn (Get $get): array => $isCreate($get)
                        ? ['group' => 'Group sessions', 'private' => 'Private sessions only']
                        : ['group' => 'Group slots', 'private' => 'Private-only slots'])
                    ->placeholder(fn (Get $get): string => $isCreate($get) ? 'Group sessions' : 'Any slot'),
            ]),
            Section::make('When (Pakistan time)')->columns(2)->schema([
                DatePicker::make('from')->label('From')->required(),
                DatePicker::make('until')->label('Until')->required()->afterOrEqual('from'),
                CheckboxList::make('days')->label('Days of the week')->required()->columns(4)->columnSpanFull()
                    ->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday']),
                TimePicker::make('match_start')->label('Only slots starting at')->seconds(false)
                    ->helperText('Leave empty for every slot on those days.')
                    ->hidden($isCreate),
            ]),
            Section::make(fn (Get $get): string => $isCreate($get) ? 'New slots' : 'Change to')->columns(2)->visible($changes)->schema([
                TimePicker::make('start_time')->label(fn (Get $get): string => $isCreate($get) ? 'Starts' : 'New start (optional)')->seconds(false)
                    ->required($isCreate),
                TimePicker::make('end_time')->label(fn (Get $get): string => $isCreate($get) ? 'Ends' : 'New end (optional)')->seconds(false)
                    ->required(fn (Get $get): bool => $isCreate($get) || filled($get('start_time'))),
                TextInput::make('capacity')->label(fn (Get $get): string => $isCreate($get) ? 'Riders' : 'New capacity (optional)')->integer()
                    ->minValue(CreateManualSlot::MIN_CAPACITY)->maxValue(CreateManualSlot::MAX_CAPACITY)
                    ->required($isCreate)
                    ->helperText('Never below the seats already taken.'),
                Select::make('trainer_id')->label(fn (Get $get): string => $isCreate($get) ? 'Trainer' : 'New trainer (optional)')
                    ->options(fn (): array => TrainerOptions::all())
                    ->placeholder(fn (Get $get): string => $isCreate($get) ? 'Nobody yet' : 'Unchanged'),
            ]),
            Section::make('Reason')->visible(fn (Get $get): bool => self::operation($get) === BulkScheduleOperation::Cancel)->schema([
                Textarea::make('reason')->label('Reason for cancelling (customers see it)')->rows(2)->maxLength(500)->required(),
            ]),
        ])->statePath('data');
    }

    public function preview(PreviewBulkSchedule $preview): void
    {
        abort_unless(self::canAccess(), 403);

        /** @var array<string, mixed> $state */
        $state = $this->form->getState();
        $this->summary = null;

        try {
            $result = $preview->handle(BulkScheduleCriteria::fromForm($state));
        } catch (InvalidArgumentException $e) {
            $this->resetPreview();
            Notification::make()->danger()->title('Can’t preview')->body($e->getMessage())->send();

            return;
        }

        $this->previewedWith = $state;
        $this->previewRows = $result->toArray();
    }

    public function applyAction(): Action
    {
        return Action::make('apply')
            ->label(function (): string {
                $count = count($this->previewObject()?->actionable() ?? []);

                return 'Apply '.$count.' '.($count === 1 ? 'change' : 'changes');
            })
            ->icon(Heroicon::OutlinedCheck)
            ->visible(fn (): bool => ($this->previewObject()?->actionable() ?? []) !== [])
            ->requiresConfirmation()
            ->modalHeading('Apply these changes?')
            ->modalDescription(function (): string {
                $riders = $this->previewObject()?->ridersAffected() ?? 0;

                return $riders > 0
                    ? sprintf('%d booked %s are in slots that move or are cancelled. Their customers are emailed straight away.', $riders, $riders === 1 ? 'rider' : 'riders')
                    : 'Nobody booked is affected.';
            })
            ->modalSubmitActionLabel('Apply')
            ->action(function (ApplyBulkSchedule $apply): void {
                $user = auth()->user();
                abort_unless($user instanceof User && self::canAccess(), 403);

                $preview = $this->previewObject();

                if ($preview === null || $this->previewedWith === null) {
                    return;
                }

                try {
                    $result = $apply->handle(BulkScheduleCriteria::fromForm($this->previewedWith), $preview->actionable(), $user);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Not applied')->body($e->getMessage())->send();

                    return;
                }

                $this->summary = $result->toArray();
                $this->resetPreview();

                Notification::make()
                    ->title($result->failures === [] ? 'Schedule updated' : 'Schedule updated, with problems')
                    ->{$result->failures === [] ? 'success' : 'warning'}()
                    ->send();
            });
    }

    /** The select holds the enum once chosen, or its value as filled. */
    private static function operation(Get $get): ?BulkScheduleOperation
    {
        $value = $get('operation');

        return $value instanceof BulkScheduleOperation ? $value : BulkScheduleOperation::tryFrom((string) $value);
    }

    public function resetPreview(): void
    {
        $this->previewRows = null;
        $this->previewedWith = null;
    }

    public function previewObject(): ?BulkSchedulePreview
    {
        return $this->previewRows === null ? null : BulkSchedulePreview::fromArray($this->previewRows);
    }

    /**
     * @return array<int, string>
     */
    public function trainerNames(): array
    {
        return TrainerOptions::all();
    }
}
