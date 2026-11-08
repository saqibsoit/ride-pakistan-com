<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SlotStatus;
use App\Http\Controllers\Team\RunSheetController;
use App\Models\Slot;
use App\Models\User;
use App\Support\SessionTimes;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** A trainer's panel home (milestone 10b): their sessions, linking to the run sheets. */
class MySessions extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    // Rendered with the page: the dashboard is the first thing staff open.
    protected static bool $isLazy = false;

    protected ?string $heading = 'My sessions';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isTrainer();
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $today = SessionTimes::now()->startOfDay();
        $mine = Slot::query()
            ->withSeatCounts()
            ->where('trainer_id', $user->id)
            ->where('status', '!=', SlotStatus::Cancelled)
            ->where('starts_at', '>=', $today->utc())
            ->orderBy('starts_at');

        $todays = (clone $mine)->where('starts_at', '<', $today->addDay()->utc())->get();
        $next = (clone $mine)->where('starts_at', '<', $today->addDays(RunSheetController::DAYS_AHEAD)->utc())->count();
        $url = route('team.sessions.index');

        return [
            Stat::make('Today', $todays->isEmpty() ? 'No sessions' : $todays->count().' '.($todays->count() === 1 ? 'session' : 'sessions'))
                ->description($todays->map(fn (Slot $slot): string => $slot->localStartsAt()->format('H:i').' · '.(int) $slot->seats_booked.' riders')->implode(', ') ?: 'Open the run sheets')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->url($url),
            Stat::make('Next '.RunSheetController::DAYS_AHEAD.' days', (string) $next)
                ->description('Sessions assigned to you')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url($url),
        ];
    }
}
