<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RegenerateSlots;
use App\Models\ScheduleRule;

/** Rule changes flow through to future empty slots (GenerateSlots). */
final class ScheduleRuleObserver
{
    public function saved(ScheduleRule $rule): void
    {
        RegenerateSlots::dispatch()->afterCommit();
    }

    public function deleted(ScheduleRule $rule): void
    {
        RegenerateSlots::dispatch()->afterCommit();
    }
}
