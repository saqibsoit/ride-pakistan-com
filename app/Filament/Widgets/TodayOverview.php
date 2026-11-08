<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\Dashboard\TodaySummary;
use App\Filament\Resources\CreditPacks\CreditPackResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Slots\SlotResource;
use App\Models\CreditPack;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Slot;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;

/**
 * The "Today" dashboard cards (milestone 10b). Figures come from
 * TodaySummary; each card links to the list it summarises, filtered.
 * Sessions and leads for the office team; money and packs for admins.
 * Transfers waiting has its own widget (TransfersWaiting, admins).
 */
class TodayOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    // Rendered with the page: the dashboard is the first thing staff open.
    protected static bool $isLazy = false;

    protected int|array|null $columns = 3;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isTeamMember();
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $summary = new TodaySummary;
        $stats = [];

        if ($user instanceof User && $user->can('viewAny', Slot::class)) {
            $today = $summary->today();
            [$saturday, $monday] = $summary->weekend();

            $stats[] = $this->sessions('Sessions today', $summary->sessionsToday(), $today, $today);
            $stats[] = $this->sessions('This weekend', $summary->sessionsThisWeekend(), $saturday, $monday->subDay());
        }

        if ($user instanceof User && $user->can('viewAny', Lead::class)) {
            $due = $summary->followUpsDue();
            $stats[] = Stat::make('Follow-ups due', (string) $due)
                ->description('Today or overdue')
                ->icon(Heroicon::OutlinedPhone)
                ->color($due > 0 ? 'warning' : 'gray')
                ->url(LeadResource::getUrl('index', ['filters' => ['follow_up_due' => ['isActive' => true]]]));

            $stats[] = Stat::make('New leads this week', (string) $summary->newLeadsThisWeek())
                ->description('Since Monday')
                ->icon(Heroicon::OutlinedUserPlus)
                ->url(LeadResource::getUrl('index', ['filters' => ['created' => ['from' => $summary->weekStart()->toDateString()]]]));
        }

        if ($user instanceof User && $user->can('viewAny', Order::class)) {
            $change = $summary->revenueChangePercent();
            $month = $summary->monthStart();

            $stats[] = Stat::make('Revenue this month', Money::format($summary->revenue()))
                ->description('Last month '.Money::format($summary->revenue(1)).($change === null ? '' : sprintf(' (%s%d%%)', $change >= 0 ? '+' : '−', abs($change))))
                ->descriptionIcon($change !== null && $change < 0 ? Heroicon::OutlinedArrowTrendingDown : Heroicon::OutlinedArrowTrendingUp)
                ->icon(Heroicon::OutlinedBanknotes)
                ->url(OrderResource::getUrl('index', ['filters' => ['paid' => [
                    'from' => $month->toDateString(),
                    'until' => $month->endOfMonth()->toDateString(),
                ]]]));
        }

        if ($user instanceof User && $user->can('viewAny', CreditPack::class)) {
            ['packs' => $packs, 'credits' => $credits] = $summary->packsExpiringSoon();

            $stats[] = Stat::make('Packs expiring in '.TodaySummary::EXPIRING_DAYS.' days', (string) $packs)
                ->description($credits.' unused '.($credits === 1 ? 'credit' : 'credits'))
                ->icon(Heroicon::OutlinedTicket)
                ->color($packs > 0 ? 'warning' : 'gray')
                ->url(CreditPackResource::getUrl('index', ['filters' => ['expiring' => ['isActive' => true]]]));
        }

        return $stats;
    }

    /** @param  Collection<int, Slot>  $slots */
    private function sessions(string $label, Collection $slots, CarbonImmutable $from, CarbonImmutable $until): Stat
    {
        ['sessions' => $count, 'riders' => $riders, 'capacity' => $capacity] = TodaySummary::occupancy($slots);

        $times = $slots->map(fn (Slot $slot): string => $slot->localStartsAt()->format('D H:i').' '.(int) $slot->seats_booked.'/'.$slot->capacity)->implode(' · ');

        return Stat::make($label, $count === 0 ? 'None' : $riders.'/'.$capacity.' riders')
            ->description($count === 0 ? 'No sessions' : $count.' '.($count === 1 ? 'session' : 'sessions').': '.$times)
            ->icon(Heroicon::OutlinedCalendarDays)
            ->url(SlotResource::getUrl('index', ['filters' => [
                'upcoming' => ['value' => null],
                'dates' => ['from' => $from->toDateString(), 'until' => $until->toDateString()],
            ]]));
    }
}
