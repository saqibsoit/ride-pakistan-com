<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use App\Actions\Exports\ExportCustomers;
use App\Enums\ExportFormat;
use App\Enums\LeadSource;
use App\Enums\UserRole;
use App\Filament\Pages\Exports;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\User;
use App\Support\CustomerStats;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customers list (prompt 14): contact details, bookings, total paid, last
 * booking and lead source (CustomerStats), filters, and an admin-only
 * CSV/Excel export of the filtered list.
 */
class CustomersTable
{
    public static function configure(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');
        $local = fn (mixed $at): string => $at === null ? '' : CarbonImmutable::parse((string) $at, 'UTC')->setTimezone($tz)->format('j M Y');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => CustomerStats::apply($query->withCount('riders')))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->placeholder('Not given yet'),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('whatsapp_phone')->label('WhatsApp')->searchable()->placeholder('—')->copyable(),
                TextColumn::make('phone')->searchable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('role')->badge()->toggleable(),
                TextColumn::make('created_at')->label('Joined')
                    ->formatStateUsing(fn (User $record): string => $local($record->created_at))
                    ->sortable(),
                TextColumn::make('bookings_count')->label('Bookings')->numeric()->sortable(),
                TextColumn::make('total_paid_minor')->label('Total paid')
                    ->formatStateUsing(fn (mixed $state): string => Money::format((int) $state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('last_booked_at')->label('Last booking')
                    ->formatStateUsing(fn (mixed $state): string => $local($state))
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('lead_source')->label('Source')
                    ->formatStateUsing(fn (mixed $state): string => LeadSource::tryFrom((string) $state)?->label() ?? '—')
                    ->placeholder('—'),
                TextColumn::make('riders_count')->label('Riders')->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')->label('Account')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (User $record): string => $record->isAnonymised() ? 'Anonymised' : 'Deletion requested')
                    ->placeholder('Active'),
            ])
            ->filters([
                Filter::make('joined')
                    ->label('Signed up')
                    ->schema([
                        DatePicker::make('from')->label('Signed up from'),
                        DatePicker::make('until')->label('Signed up until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where('users.created_at', '>=', CarbonImmutable::parse($date, $tz)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where('users.created_at', '<=', CarbonImmutable::parse($date, $tz)->endOfDay()->utc()))),
                TernaryFilter::make('booked')
                    ->label('Bookings')
                    ->placeholder('Everyone')
                    ->trueLabel('Has booked')
                    ->falseLabel('Never booked')
                    ->queries(
                        true: fn (Builder $query): Builder => CustomerStats::whereBooked($query, true),
                        false: fn (Builder $query): Builder => CustomerStats::whereBooked($query, false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(LeadSource::class)
                    ->query(fn (Builder $query, array $data): Builder => ($source = LeadSource::tryFrom((string) ($data['value'] ?? ''))) === null
                        ? $query
                        : CustomerStats::whereLeadSource($query, $source)),
                SelectFilter::make('role')->options(UserRole::class),
                TrashedFilter::make()->label('Deleted accounts'),
            ])
            ->headerActions([
                self::exportAction(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /** Admins only, the shared exports limit (Exports::PER_HOUR an hour), logged by ExportCustomers. */
    public static function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => auth()->user()?->can('export-data') === true)
            ->modalHeading('Export customers')
            ->modalDescription('The customers the current filters show, with every column.')
            ->modalSubmitActionLabel('Download')
            ->schema([
                Select::make('format')->options(ExportFormat::class)->required()->default(ExportFormat::Csv->value),
            ])
            ->action(function (array $data, Action $action, ListCustomers $livewire, ExportCustomers $export): ?StreamedResponse {
                $user = auth()->user();
                abort_unless($user instanceof User && $user->can('export-data'), 403);

                if (RateLimiter::tooManyAttempts('exports:'.$user->id, Exports::PER_HOUR)) {
                    Notification::make()->danger()->title('Too many exports')->body('Try again in an hour.')->send();
                    $action->halt();

                    return null;
                }

                RateLimiter::hit('exports:'.$user->id, 3600);

                $query = $livewire->getFilteredTableQuery();
                abort_if($query === null, 404);

                $file = $export->handle(
                    $query,
                    $data['format'] instanceof ExportFormat ? $data['format'] : ExportFormat::from((string) $data['format']),
                    $user,
                    array_filter((array) $livewire->tableFilters),
                );

                return response()->streamDownload(function () use ($file): void {
                    readfile($file->path);
                    @unlink($file->path);
                }, $file->filename, [
                    'Content-Type' => $file->mimeType,
                    'Cache-Control' => 'no-store, private',
                ]);
            });
    }
}
