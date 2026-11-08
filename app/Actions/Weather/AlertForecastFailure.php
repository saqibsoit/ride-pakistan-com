<?php

declare(strict_types=1);

namespace App\Actions\Weather;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WeatherFetch;
use App\Notifications\WeatherForecastsFailing;
use App\Support\Notifier;
use Carbon\CarbonImmutable;

/**
 * Emails every admin once when forecasts have failed for FAILING_HOURS
 * (milestone 10f): measured from the first failure after the last success.
 * One email per outage (deduplicated on that first failure); a success
 * ends the outage, and a new one can alert again.
 */
final class AlertForecastFailure
{
    public const FAILING_HOURS = 12;

    public function __construct(private readonly Notifier $notifier) {}

    /** @return int admins emailed */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $lastSuccess = WeatherFetch::lastSuccess();

        $firstFailure = WeatherFetch::query()
            ->failed()
            ->when($lastSuccess !== null, fn ($query) => $query->where('attempted_at', '>', $lastSuccess?->attempted_at))
            ->oldest('attempted_at')
            ->oldest('id')
            ->first();

        if ($firstFailure === null || $firstFailure->attempted_at->greaterThan($now->subHours(self::FAILING_HOURS))) {
            return 0;
        }

        $latestFailure = WeatherFetch::query()->failed()->latest('attempted_at')->latest('id')->first() ?? $firstFailure;
        $sent = 0;

        User::query()->where('role', UserRole::Admin)->get()->each(function (User $admin) use ($firstFailure, $latestFailure, $lastSuccess, &$sent): void {
            $notification = new WeatherForecastsFailing($firstFailure, $lastSuccess?->attempted_at, (string) $latestFailure->error);

            if ($this->notifier->send($admin, $notification, Notifier::key(WeatherForecastsFailing::type(), $firstFailure, $admin))) {
                $sent++;
            }
        });

        return $sent;
    }
}
