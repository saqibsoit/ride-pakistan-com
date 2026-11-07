<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Models\ScheduleRule;
use App\Models\Slot;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates slots for the coming weeks from the active schedule rules.
 *
 * - Idempotent: one slot per start time (unique on starts_at).
 * - Times are wall-clock in the display timezone, converted to UTC per
 *   date, so a DST change keeps sessions at the same local time.
 * - Only future, open slots with nothing attached (no booking, hold or
 *   order line) follow rule changes or are removed when no rule produces
 *   them. Slots in use, closed or cancelled slots and past slots are never
 *   touched.
 */
final class GenerateSlots
{
    public const MAX_WEEKS = 52;

    public function __construct(private readonly Settings $settings) {}

    public function handle(?int $weeks = null): SlotGenerationResult
    {
        $weeks ??= $this->settings->int(SettingKey::SlotHorizonWeeks) ?? 8;
        $weeks = max(1, min(self::MAX_WEEKS, $weeks));

        $now = CarbonImmutable::now();
        $today = $now->setTimezone(Slot::displayTimezone())->startOfDay();
        $until = $today->addWeeks($weeks);

        $desired = $this->desiredSlots($today, $until, $now);

        return DB::transaction(function () use ($desired, $now, $until): SlotGenerationResult {
            /** @var array<string, Slot> $existing */
            $existing = Slot::query()
                ->where('starts_at', '>', $now)
                ->where('starts_at', '<', $until->utc())
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Slot $slot): string => self::key($slot->starts_at))
                ->all();

            $inUse = array_flip(Slot::query()
                ->whereKey(array_map(fn (Slot $slot): int => $slot->id, $existing))
                ->inUse()
                ->pluck('id')
                ->all());

            $result = new SlotGenerationResult;

            foreach ($desired as $key => $wanted) {
                $slot = $existing[$key] ?? null;

                if ($slot === null) {
                    Slot::query()->create($wanted);
                    $result->created++;

                    continue;
                }

                if ($slot->status !== SlotStatus::Open || isset($inUse[$slot->id])) {
                    $result->kept++;

                    continue;
                }

                $slot->fill($wanted);

                if ($slot->isDirty()) {
                    $slot->save();
                    $result->updated++;
                }
            }

            foreach (array_diff_key($existing, $desired) as $slot) {
                if ($slot->status !== SlotStatus::Open || isset($inUse[$slot->id])) {
                    $result->kept++;

                    continue;
                }

                $slot->delete();
                $result->deleted++;
            }

            return $result;
        });
    }

    /**
     * @return array<string, array{schedule_rule_id: int, starts_at: CarbonImmutable, ends_at: CarbonImmutable, capacity: int}>
     */
    private function desiredSlots(CarbonImmutable $from, CarbonImmutable $until, CarbonImmutable $now): array
    {
        $rules = ScheduleRule::query()->active()->orderBy('id')->get();
        $desired = [];

        for ($date = $from; $date->lessThan($until); $date = $date->addDay()) {
            foreach ($rules as $rule) {
                if (! $rule->appliesOn($date)) {
                    continue;
                }

                $startsAt = $rule->startsOn($date)->utc();

                if ($startsAt->lessThanOrEqualTo($now)) {
                    continue;
                }

                // Two rules with the same start: the older rule wins.
                $desired[self::key($startsAt)] ??= [
                    'schedule_rule_id' => $rule->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $rule->endsOn($date)->utc(),
                    'capacity' => $rule->capacity,
                ];
            }
        }

        return $desired;
    }

    private static function key(CarbonImmutable $startsAt): string
    {
        return $startsAt->utc()->format('Y-m-d H:i:s');
    }
}
