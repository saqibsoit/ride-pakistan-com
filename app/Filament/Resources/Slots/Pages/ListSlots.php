<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots\Pages;

use App\Actions\Slots\CreateManualSlot;
use App\Actions\Slots\GenerateSlots;
use App\Enums\SettingKey;
use App\Exceptions\SlotNotCreated;
use App\Filament\Resources\Slots\SlotResource;
use App\Filament\Support\TrainerOptions;
use App\Models\Slot;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSlots extends ListRecords
{
    protected static string $resource = SlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')
                ->label('Week view')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(SlotResource::getUrl('calendar')),
            $this->createManualAction(),
            Action::make('generate')
                ->label('Generate slots now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => self::user()?->can('generate', Slot::class) === true)
                ->requiresConfirmation()
                ->modalDescription('Creates missing slots from the schedule rules. Slots with bookings or holds, closed or cancelled slots, and manual slots are never changed.')
                ->action(function (GenerateSlots $generate): void {
                    abort_unless(self::user()?->can('generate', Slot::class) === true, 403);

                    $result = $generate->handle();

                    activity('slots')->causedBy(self::user())->event('generated')
                        ->withProperties(['created' => $result->created, 'updated' => $result->updated, 'deleted' => $result->deleted])
                        ->log('Slots generated: '.$result->summary());

                    Notification::make()->success()->title('Slots up to date')->body(ucfirst($result->summary()).'.')->send();
                }),
        ];
    }

    private function createManualAction(): Action
    {
        return Action::make('createManual')
            ->label('Add manual slot')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn (): bool => self::user()?->can('create', Slot::class) === true)
            ->modalHeading('Add a manual slot')
            ->modalDescription('A one-off session outside the schedule rules, on any date. The generator never changes or removes it.')
            ->modalSubmitActionLabel('Add slot')
            ->schema([
                DatePicker::make('date')->label('Date (Pakistan time)')->required()
                    ->minDate(fn (): string => CarbonImmutable::now(Slot::displayTimezone())->toDateString()),
                TimePicker::make('start_time')->label('Starts (Pakistan time)')->required()->seconds(false),
                TimePicker::make('end_time')->label('Ends (Pakistan time)')->required()->seconds(false)->after('start_time'),
                TextInput::make('capacity')->label('Riders')->required()->integer()
                    ->minValue(CreateManualSlot::MIN_CAPACITY)->maxValue(CreateManualSlot::MAX_CAPACITY)
                    ->default(fn (): ?int => app(Settings::class)->int(SettingKey::RidersPerSlot)),
                Toggle::make('private_only')->label('Private sessions only')
                    ->helperText('Group sessions and package credits can’t book it; it shows as “Private only” on the schedule.'),
                Select::make('trainer_id')->label('Trainer')
                    ->options(fn (): array => TrainerOptions::all())
                    ->placeholder('Nobody yet'),
            ])
            ->action(function (array $data, Action $action, CreateManualSlot $create): void {
                $user = self::user();
                abort_unless($user?->can('create', Slot::class) === true, 403);

                $tz = Slot::displayTimezone();
                $date = (string) $data['date'];

                try {
                    $slot = $create->handle(
                        CarbonImmutable::parse($date.' '.$data['start_time'], $tz),
                        CarbonImmutable::parse($date.' '.$data['end_time'], $tz),
                        (int) $data['capacity'],
                        $user,
                        (bool) ($data['private_only'] ?? false),
                        filled($data['trainer_id'] ?? null) ? User::query()->find((int) $data['trainer_id']) : null,
                    );
                } catch (SlotNotCreated $exception) {
                    Notification::make()->danger()->title('Slot not added')->body($exception->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Manual slot added')->body($slot->label())->send();
            });
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
