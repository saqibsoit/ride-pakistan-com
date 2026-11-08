<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\LeadConversionTable;
use App\Filament\Widgets\LeadsBySourceChart;
use App\Filament\Widgets\MySessions;
use App\Filament\Widgets\TodayOverview;
use App\Filament\Widgets\TransfersWaiting;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Panel home, "Today" (milestone 10b): what needs doing now. Each widget
 * decides who sees it (canView): sessions and leads for the team, money,
 * transfers and packs for admins, "My sessions" for trainers.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Today';

    public function getWidgets(): array
    {
        return [
            MySessions::class,
            TodayOverview::class,
            TransfersWaiting::class,
            LeadsBySourceChart::class,
            LeadConversionTable::class,
        ];
    }
}
