<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadActions;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['assignee']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->weight('semibold')
                    ->description(fn (Lead $record): string => $record->contactLine()),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('source')->badge()->color('gray')->sortable(),
                TextColumn::make('interest')->formatStateUsing(fn (Lead $record): string => $record->interest?->label() ?? '—')->toggleable(),
                TextColumn::make('assignee.name')->label('Assignee')->placeholder('Nobody')->toggleable(),
                TextColumn::make('next_follow_up_at')
                    ->label('Follow-up')
                    ->date('j M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (Lead $record): ?string => $record->isFollowUpDue() ? 'danger' : null)
                    ->icon(fn (Lead $record): ?string => $record->isFollowUpDue() ? 'heroicon-o-exclamation-triangle' : null)
                    ->description(fn (Lead $record): ?string => $record->isFollowUpDue() ? 'Due' : null),
                TextColumn::make('utm_campaign')->label('Campaign')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Received')->dateTime('j M Y H:i', timezone: $tz)->sortable(),
                // Search-only columns.
                TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('instagram_handle')->label('Instagram')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(LeadStatus::class)->multiple(),
                SelectFilter::make('source')->options(LeadSource::class)->multiple(),
                SelectFilter::make('assigned_to')->label('Assignee')->options(fn (): array => LeadActions::teamOptions()),
                Filter::make('created')
                    ->label('Received')
                    ->schema([
                        DatePicker::make('from')->label('Received from'),
                        DatePicker::make('until')->label('Received until'),
                    ])
                    ->query(function (Builder $query, array $data) use ($tz): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where('created_at', '>=', CarbonImmutable::parse($date, $tz)->startOfDay()->utc()))
                            ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where('created_at', '<=', CarbonImmutable::parse($date, $tz)->endOfDay()->utc()));
                    }),
                Filter::make('follow_up_due')
                    ->label('Follow-up due')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified])
                        ->whereDate('next_follow_up_at', '<=', now($tz)->toDateString())),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    LeadActions::changeStatus(),
                    LeadActions::assign(),
                    LeadActions::followUp(),
                    LeadActions::addNote(),
                    LeadActions::convert(),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
