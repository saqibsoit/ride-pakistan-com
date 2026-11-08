<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\Leads\LeadReport;
use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Support\Colors\Color;
use Filament\Widgets\ChartWidget;

/** Dashboard (admins): new leads per Pakistan week, stacked by source. */
class LeadsBySourceChart extends ChartWidget
{
    protected static ?int $sort = 10;

    protected ?string $heading = 'Leads by source per week';

    protected ?string $description = 'Last 12 weeks, Monday to Sunday (Pakistan time).';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    /** Filament palette shades, one per source, in LeadSource order. */
    private const PALETTE = [Color::Pink, Color::Amber, Color::Sky, Color::Emerald, Color::Violet, Color::Orange, Color::Teal, Color::Slate];

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewReports', Lead::class);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $report = app(LeadReport::class)->weeklyBySource();
        $sources = LeadSource::cases();
        $datasets = [];

        foreach ($report['series'] as $source => $counts) {
            $case = LeadSource::from($source);
            $palette = self::PALETTE[array_search($case, $sources, true) % count(self::PALETTE)];

            $datasets[] = [
                'label' => $case->label(),
                'data' => $counts,
                'backgroundColor' => $palette[500],
                'borderColor' => $palette[600],
            ];
        }

        return [
            'labels' => array_map(fn (string $week): string => CarbonImmutable::parse($week)->format('j M'), $report['weeks']),
            'datasets' => $datasets,
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
