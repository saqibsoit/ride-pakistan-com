<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\SettingKey;
use App\Models\Slot;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/** The cancel / reschedule cutoff (setting booking.cancellation_cutoff_hours). */
final class CancellationCutoff
{
    public function __construct(private readonly Settings $settings) {}

    public function hours(): int
    {
        return $this->settings->int(SettingKey::CancellationCutoffHours) ?? 48;
    }

    public function deadline(Slot $slot): CarbonImmutable
    {
        return $slot->starts_at->subHours($this->hours());
    }

    public function isBefore(Slot $slot, ?CarbonImmutable $now = null): bool
    {
        return ! ($now ?? CarbonImmutable::now())->isAfter($this->deadline($slot));
    }
}
