<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Enums\SlotStatus;
use App\Models\ScheduleRule;
use App\Models\Slot;
use Illuminate\Support\Facades\DB;

/**
 * When a schedule rule's trainer changes (milestone 10b), its future slots
 * that still have the rule's previous trainer follow the change. Slots an
 * admin gave another trainer keep theirs; past and cancelled slots are
 * history and never change. Booked slots follow too: the trainer is not a
 * seat matter.
 */
final class ApplyRuleTrainer
{
    public function handle(ScheduleRule $rule, ?int $previousTrainerId): int
    {
        return DB::transaction(function () use ($rule, $previousTrainerId): int {
            $slots = Slot::query()
                ->where('schedule_rule_id', $rule->id)
                ->where('starts_at', '>', now())
                ->where('status', '!=', SlotStatus::Cancelled)
                ->when(
                    $previousTrainerId === null,
                    fn ($query) => $query->whereNull('trainer_id'),
                    fn ($query) => $query->where('trainer_id', $previousTrainerId),
                )
                ->lockForUpdate()
                ->get();

            foreach ($slots as $slot) {
                $slot->trainer_id = $rule->trainer_id;
                $slot->save();
            }

            if ($slots->isNotEmpty()) {
                activity('slots')
                    ->performedOn($rule)
                    ->causedBy(auth()->user())
                    ->event('trainer_applied')
                    ->withProperties(['from' => $previousTrainerId, 'to' => $rule->trainer_id, 'slot_ids' => $slots->modelKeys()])
                    ->log(sprintf('Rule trainer applied to %d future slot(s)', $slots->count()));
            }

            return $slots->count();
        });
    }
}
