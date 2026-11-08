<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Slots\ApplyRuleTrainer;
use App\Jobs\RegenerateSlots;
use App\Models\ScheduleRule;

/**
 * Rule changes flow through to future empty slots (GenerateSlots), and a
 * new trainer to the future slots that had the old one (ApplyRuleTrainer).
 */
final class ScheduleRuleObserver
{
    public function updated(ScheduleRule $rule): void
    {
        if ($rule->wasChanged('trainer_id')) {
            $previous = $rule->getOriginal('trainer_id');

            app(ApplyRuleTrainer::class)->handle($rule, $previous === null ? null : (int) $previous);
        }
    }

    public function saved(ScheduleRule $rule): void
    {
        RegenerateSlots::dispatch()->afterCommit();
    }

    public function deleted(ScheduleRule $rule): void
    {
        RegenerateSlots::dispatch()->afterCommit();
    }
}
