<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\Leads\LeadReport;
use App\Models\Lead;
use App\Models\User;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Dashboard (admins): leads, conversion to paid orders and revenue (net
 * of refunds, first touch) per source over the last 12 weeks.
 */
class LeadConversionTable extends TableWidget
{
    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewReports', Lead::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Conversion and revenue by source')
            ->description('Leads from the last 12 weeks. Converted: the customer paid an order after the enquiry.')
            ->records(fn (): array => collect(app(LeadReport::class)->bySource())
                ->mapWithKeys(fn (array $row): array => [$row['source']->value => [
                    'source' => $row['source']->label(),
                    'leads' => $row['leads'],
                    'converted' => $row['converted'],
                    'rate' => $row['rate'],
                    'revenue_minor' => $row['revenue_minor'],
                ]])
                ->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('source')->label('Source'),
                TextColumn::make('leads')->label('Leads')->numeric(),
                TextColumn::make('converted')->label('Paid')->numeric(),
                TextColumn::make('rate')->label('Conversion')->formatStateUsing(fn (float|int $state): string => number_format((float) $state, 1).'%'),
                TextColumn::make('revenue_minor')->label('Revenue')->formatStateUsing(fn (int $state): string => Money::format($state)),
            ])
            ->emptyStateHeading('No leads in the last 12 weeks');
    }
}
