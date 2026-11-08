<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Weather\CheckSlotWeather;
use App\Actions\Weather\FetchForecast;
use Illuminate\Console\Command;

/**
 * Hourly (milestone 10f): fetches the forecast when a scheduled fetch is
 * due (06:00 and 18:00 Pakistan time, or retrying after a failure), then
 * re-assesses the slots of the next 48 hours and emails level changes.
 */
final class CheckWeatherCommand extends Command
{
    protected $signature = 'weather:check {--force : Fetch the forecast now, even if it is not due}';

    protected $description = 'Fetch the venue forecast when due and email the team about weather changes for the next 48 hours';

    public function handle(FetchForecast $fetch, CheckSlotWeather $check): int
    {
        if (! $fetch->enabled()) {
            $this->info('Weather alerts are off or the venue coordinates are not set.');

            return self::SUCCESS;
        }

        if ($this->option('force') || $fetch->isDue()) {
            $result = $fetch->handle();
            $this->info($result === true ? 'Forecast fetched.' : 'Forecast fetch failed (logged).');
        }

        $sent = $check->handle();
        $this->info('Weather alerts sent: '.$sent.'.');

        return self::SUCCESS;
    }
}
