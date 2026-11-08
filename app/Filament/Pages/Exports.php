<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Exports\RunExport;
use App\Enums\ExportDataset;
use App\Enums\ExportFormat;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Admin exports (milestone 10b): orders, payments, refunds, bookings and
 * leads for a Pakistan date range, as CSV or Excel. Built on the spot and
 * downloaded straight away (RunExport); every export is in the audit log.
 * Limited to 10 an hour per admin.
 */
class Exports extends Page
{
    public const PER_HOUR = 10;

    protected string $view = 'filament.pages.exports';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 105;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('export-data');
    }

    protected function getHeaderActions(): array
    {
        return [$this->exportAction()];
    }

    public function exportAction(): Action
    {
        $tz = (string) config('app.display_timezone');

        return Action::make('export')
            ->label('New export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading('Export data')
            ->modalSubmitActionLabel('Download')
            ->schema([
                Select::make('dataset')->label('What')->options(ExportDataset::class)->required()->default(ExportDataset::Orders->value),
                DatePicker::make('from')->label('From (Pakistan date)')->required()
                    ->default(fn (): string => CarbonImmutable::now($tz)->startOfMonth()->toDateString()),
                DatePicker::make('until')->label('Until (Pakistan date)')->required()->afterOrEqual('from')
                    ->default(fn (): string => CarbonImmutable::now($tz)->toDateString()),
                Select::make('format')->options(ExportFormat::class)->required()->default(ExportFormat::Csv->value),
            ])
            ->action(function (array $data, Action $action, RunExport $export) use ($tz): ?StreamedResponse {
                $user = auth()->user();
                abort_unless($user instanceof User && $user->can('export-data'), 403);

                if (RateLimiter::tooManyAttempts('exports:'.$user->id, self::PER_HOUR)) {
                    Notification::make()->danger()->title('Too many exports')->body('Try again in an hour.')->send();
                    $action->halt();

                    return null;
                }

                RateLimiter::hit('exports:'.$user->id, 3600);

                try {
                    $file = $export->handle(
                        $data['dataset'] instanceof ExportDataset ? $data['dataset'] : ExportDataset::from((string) $data['dataset']),
                        CarbonImmutable::parse((string) $data['from'], $tz),
                        CarbonImmutable::parse((string) $data['until'], $tz),
                        $data['format'] instanceof ExportFormat ? $data['format'] : ExportFormat::from((string) $data['format']),
                        $user,
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Not exported')->body($e->getMessage())->send();
                    $action->halt();

                    return null;
                }

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
