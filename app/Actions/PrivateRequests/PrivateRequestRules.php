<?php

declare(strict_types=1);

namespace App\Actions\PrivateRequests;

use App\Enums\SettingKey;
use App\Models\Slot;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * The private request settings (prompt 15B), with safe fallbacks:
 * earliest start 07:00, latest end 18:00, at least 48 hours ahead,
 * 2-hour sessions, 2 hours to answer, 2 hours to pay.
 */
final class PrivateRequestRules
{
    /** Requested start times are on these minute steps. */
    public const STEP_MINUTES = 15;

    public function __construct(private readonly Settings $settings) {}

    public function earliestStartHour(): int
    {
        return min(23, max(0, $this->settings->int(SettingKey::PrivateEarliestStartHour) ?? 7));
    }

    public function latestEndHour(): int
    {
        return min(24, max(1, $this->settings->int(SettingKey::PrivateLatestEndHour) ?? 18));
    }

    public function minNoticeHours(): int
    {
        return max(0, $this->settings->int(SettingKey::PrivateMinNoticeHours) ?? 48);
    }

    public function durationMinutes(): int
    {
        return max(15, $this->settings->int(SettingKey::PrivateDurationMinutes) ?? 120);
    }

    public function answerHours(): int
    {
        return max(1, $this->settings->int(SettingKey::PrivateAnswerHours) ?? 2);
    }

    public function payHours(): int
    {
        return max(1, $this->settings->int(SettingKey::PrivatePayHours) ?? 2);
    }

    /** The latest start time of day, "16:00", for a session that must end by the latest end. */
    public function latestStart(): string
    {
        $minutes = $this->latestEndHour() * 60 - $this->durationMinutes();

        return sprintf('%02d:%02d', intdiv(max(0, $minutes), 60), max(0, $minutes) % 60);
    }

    public function earliestStart(): string
    {
        return sprintf('%02d:00', $this->earliestStartHour());
    }

    /** The first Pakistan date a request can be for. */
    public function firstDate(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->addHours($this->minNoticeHours())->setTimezone(Slot::displayTimezone())->startOfDay();
    }

    /**
     * Why this start can't be requested, or null when it can.
     */
    public function problem(CarbonImmutable $startsAt, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        $local = $startsAt->setTimezone(Slot::displayTimezone());
        $minuteOfDay = $local->hour * 60 + $local->minute;

        if ($local->minute % self::STEP_MINUTES !== 0) {
            return sprintf('Choose a start time on the quarter hour (for example %s).', $this->earliestStart());
        }

        if ($minuteOfDay < $this->earliestStartHour() * 60 || $minuteOfDay + $this->durationMinutes() > $this->latestEndHour() * 60) {
            return sprintf('Private sessions run between %s and %02d:00 Pakistan time, so start between %s and %s.',
                $this->earliestStart(), $this->latestEndHour(), $this->earliestStart(), $this->latestStart());
        }

        if ($startsAt->lessThan($now->addHours($this->minNoticeHours()))) {
            return sprintf('Please request at least %d hours ahead.', $this->minNoticeHours());
        }

        return null;
    }
}
