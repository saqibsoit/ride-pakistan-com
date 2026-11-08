<?php

declare(strict_types=1);

namespace App\Actions\Weather;

use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Enums\WeatherSeverity;
use App\Models\Slot;
use App\Models\SlotWeather;
use App\Models\WeatherForecastHour;
use App\Notifications\WeatherAlert;
use App\Support\Notifier;
use App\Support\Settings;
use App\Support\Weather\SlotAssessment;
use App\Support\Weather\WeatherThresholds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Assesses every open or closed slot starting in the next 48 hours against
 * the cached forecast and emails the team when a slot's level changes
 * (milestone 10f): once per slot per change, including "all clear" after
 * an alert. A slot that was never alerted and is clear sends nothing. The
 * slot's slot_weather row is locked while deciding, so overlapping runs
 * can't send the same alert twice; each email also carries a dedupe key.
 */
final class CheckSlotWeather
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AssessSlotWeather $assess,
        private readonly WeatherRecipients $recipients,
        private readonly Notifier $notifier,
    ) {}

    /** @return int alerts emailed (one per recipient) */
    public function handle(?CarbonImmutable $now = null): int
    {
        if (! $this->settings->bool(SettingKey::WeatherEnabled)) {
            return 0;
        }

        $now ??= CarbonImmutable::now();

        $slots = Slot::query()
            ->where('status', '!=', SlotStatus::Cancelled)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addHours(Slot::WEATHER_WINDOW_HOURS))
            ->orderBy('starts_at')
            ->get();

        if ($slots->isEmpty()) {
            return 0;
        }

        /** @var CarbonImmutable $from */
        $from = $slots->min('starts_at');
        /** @var CarbonImmutable $until */
        $until = $slots->max('ends_at');

        $forecast = WeatherForecastHour::query()
            ->where('hour_at', '>=', $from->subHour())
            ->where('hour_at', '<', $until)
            ->orderBy('hour_at')
            ->get();

        $thresholds = WeatherThresholds::fromSettings($this->settings);
        $sent = 0;

        foreach ($slots as $slot) {
            $assessment = $this->assess->handle($slot, $forecast, $thresholds);

            if ($assessment !== null) {
                $sent += $this->record($slot, $assessment, $now);
            }
        }

        return $sent;
    }

    private function record(Slot $slot, SlotAssessment $assessment, CarbonImmutable $now): int
    {
        // Make sure the row exists, then lock it to decide about the alert.
        SlotWeather::query()->insertOrIgnore([
            'slot_id' => $slot->id,
            'severity' => $assessment->severity->value,
            'conditions' => json_encode($assessment->conditions(), JSON_THROW_ON_ERROR),
            'assessed_at' => $now->toDateTimeString(),
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);

        $alert = DB::transaction(function () use ($slot, $assessment, $now): ?array {
            $row = SlotWeather::query()->where('slot_id', $slot->id)->lockForUpdate()->firstOrFail();
            $previous = $row->alerted_severity;

            $row->severity = $assessment->severity;
            $row->conditions = $assessment->conditions();
            $row->assessed_at = $now;

            $changed = $assessment->severity !== ($previous ?? WeatherSeverity::Clear);

            if ($changed) {
                $row->alerted_severity = $assessment->severity;
                $row->alerted_at = $now;
            }

            $row->save();

            return $changed ? ['previous' => $previous, 'at' => $now] : null;
        });

        if ($alert === null) {
            return 0;
        }

        $sent = 0;

        foreach ($this->recipients->for($slot) as $user) {
            $notification = new WeatherAlert($slot, $assessment->severity, $alert['previous'], $assessment->conditions());
            // One email per recipient per change (from → to, at the time it was recorded).
            $key = sprintf(
                '%s:Slot:%d:%d:%s-%s:%d',
                WeatherAlert::type(),
                $slot->id,
                $user->id,
                $alert['previous']->value ?? 'none',
                $assessment->severity->value,
                $alert['at']->getTimestamp(),
            );

            if ($this->notifier->send($user, $notification, $key)) {
                $sent++;
            }
        }

        return $sent;
    }
}
