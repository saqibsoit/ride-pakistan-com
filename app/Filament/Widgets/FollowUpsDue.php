<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Dashboard list of the leads due for follow-up today or overdue
 * (milestone 10c), the same leads as the 09:00 digest. Staff and admins.
 */
class FollowUpsDue extends TableWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Follow-ups due';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Lead::class);
    }

    public function table(Table $table): Table
    {
        $today = now((string) config('app.display_timezone'))->toDateString();

        return $table
            ->query(fn () => Lead::query()
                ->with('assignee')
                ->whereIn('status', [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified])
                ->whereDate('next_follow_up_at', '<=', $today))
            ->defaultSort('next_follow_up_at')
            ->paginated([10])
            ->emptyStateHeading('No follow-ups due today')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('contact')->state(fn (Lead $record): string => $record->contactLine())->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('next_follow_up_at')->label('Due')->date('D j M'),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Nobody'),
            ])
            ->recordUrl(fn (Lead $record): string => LeadResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('open')->label('Open')->url(fn (Lead $record): string => LeadResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
