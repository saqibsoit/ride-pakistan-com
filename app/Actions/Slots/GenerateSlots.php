<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Enums\SettingKey;
use App\Enums\SlotSource;
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
 * - Manual slots (added by an admin through CreateManualSlot) are never
 *   changed or removed. If a rule produces a start time a manual slot
 *   already holds, the manual slot wins and no rule slot is created.
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

        $desired = self::desiredSlots(ScheduleRule::query()->active()->orderBy('id')->get(), $today, $until, $now);

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
                    $created = new Slot($wanted);
                    $created->source = SlotSource::Rule;
                    $created->save();
                    $result->created++;

                    continue;
                }

                if ($slot->isManual() || $slot->status !== SlotStatus::Open || isset($inUse[$slot->id])) {
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
                if ($slot->isManual()) {
                    continue;
                }

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
     * The slots a set of rules produces between two local dates (also used
     * by PreviewScheduleRule, which passes an unsaved rule). Rules are in
     * id order: with two rules at one start time the older one wins; an
     * unsaved rule (no id) comes last.
     *
     * @param  iterable<ScheduleRule>  $rules
     * @return array<string, array{schedule_rule_id: int|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable, capacity: int}>
     */
    public static function desiredSlots(iterable $rules, CarbonImmutable $from, CarbonImmutable $until, CarbonImmutable $now): array
    {
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

    public static function key(CarbonImmutable $startsAt): string
    {
        return $startsAt->utc()->format('Y-m-d H:i:s');
    }
}
