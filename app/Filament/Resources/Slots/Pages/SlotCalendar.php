<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots\Pages;

use App\Enums\SlotStatus;
use App\Filament\Resources\Slots\SlotResource;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Week view of slots (Monday to Sunday, Pakistan time) with seats booked,
 * held and free. Built from Filament schema components only: no extra
 * scripts or styles.
 */
class SlotCalendar extends Page
{
    protected static string $resource = SlotResource::class;

    protected static ?string $title = 'Slots: week view';

    /** Monday of the shown week (Y-m-d, Pakistan time). */
    #[Url]
    public string $week = '';

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Slot::class);
    }

    public function mount(): void
    {
        $this->week = $this->monday()->toDateString();
    }

    public function previousWeek(): void
    {
        $this->showWeek($this->monday()->subWeek());
    }

    public function nextWeek(): void
    {
        $this->showWeek($this->monday()->addWeek());
    }

    public function thisWeek(): void
    {
        $this->showWeek(self::currentMonday());
    }

    private function showWeek(CarbonImmutable $monday): void
    {
        $this->week = $monday->toDateString();

        // The content schema is built before actions run; rebuild it for the new week.
        $this->cacheSchema('content', null);
    }

    public function getSubheading(): string
    {
        $monday = $this->monday();

        return $monday->format('j M').' – '.$monday->addDays(6)->format('j M Y').' (Pakistan time)';
    }

    public function content(Schema $schema): Schema
    {
        $monday = $this->monday();
        $slots = $this->slotsByDay($monday);
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $monday->addDays($i);
            $daySlots = $slots[$date->toDateString()] ?? [];

            $days[] = Section::make($date->format('D j M'))
                ->compact()
                ->schema($daySlots === []
                    ? [Text::make('No sessions')->color('gray')]
                    : array_map(fn (Slot $slot): Section => $this->slotCard($slot), $daySlots));
        }

        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 7])->schema($days),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousWeek')->label('Previous week')->icon(Heroicon::OutlinedChevronLeft)->color('gray')
                ->action(fn () => $this->previousWeek()),
            Action::make('thisWeek')->label('This week')->color('gray')
                ->action(fn () => $this->thisWeek()),
            Action::make('nextWeek')->label('Next week')->icon(Heroicon::OutlinedChevronRight)->iconPosition('after')->color('gray')
                ->action(fn () => $this->nextWeek()),
            Action::make('list')->label('List view')->icon(Heroicon::OutlinedListBullet)
                ->url(SlotResource::getUrl('index')),
        ];
    }

    private function slotCard(Slot $slot): Section
    {
        $free = $slot->seatsFree();

        return Section::make($slot->localStartsAt()->format('H:i').'–'.$slot->localEndsAt()->format('H:i'))
            ->secondary()
            ->compact()
            ->headerActions([
                Action::make('open-'.$slot->id)
                    ->label('Open')
                    ->link()
                    ->url(SlotResource::getUrl('view', ['record' => $slot])),
            ])
            ->schema([
                Text::make($slot->status->label())
                    ->badge()
                    ->color($slot->status->color())
                    ->icon($slot->status->icon()),
                Text::make(sprintf('%d booked · %d held · %d free of %d', $slot->seatsBooked(), $slot->seatsHeld(), $free, $slot->capacity))
                    ->color($slot->status === SlotStatus::Open && $free === 0 ? 'danger' : null),
            ]);
    }

    /**
     * Slots of the week keyed by local date (Y-m-d).
     *
     * @return array<string, list<Slot>>
     */
    private function slotsByDay(CarbonImmutable $monday): array
    {
        $days = [];

        $slots = Slot::query()
            ->withSeatCounts()
            ->where('starts_at', '>=', $monday->utc())
            ->where('starts_at', '<', $monday->addWeek()->utc())
            ->orderBy('starts_at')
            ->get();

        foreach ($slots as $slot) {
            $days[$slot->localStartsAt()->toDateString()][] = $slot;
        }

        return $days;
    }

    private function monday(): CarbonImmutable
    {
        if ($this->week !== '') {
            try {
                return CarbonImmutable::parse($this->week, Slot::displayTimezone())
                    ->startOfWeek(CarbonImmutable::MONDAY)
                    ->startOfDay();
            } catch (\Throwable) {
                // Fall through to the current week for a malformed ?week=.
            }
        }

        return self::currentMonday();
    }

    private static function currentMonday(): CarbonImmutable
    {
        return CarbonImmutable::now(Slot::displayTimezone())->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }
}
