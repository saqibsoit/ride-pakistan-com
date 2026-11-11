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
 * - New slots get their rule's trainer (milestone 10b). The generator
 *   never changes the trainer of an existing slot; ApplyRuleTrainer
 *   does that when a rule's trainer changes.
 * - Manual slots (added by an admin through CreateManualSlot) are never
 *   changed or removed. If a rule produces a start time a manual slot
 *   already holds, the manual slot wins and no rule slot is created.
 * - A rule slot the bulk schedule editor moved (prompt 15A) became manual
 *   and keeps the start it replaced (replaces_starts_at): no rule slot is
 *   created there again.
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

        $rules = ScheduleRule::query()->active()->orderBy('id')->get();
        $desired = self::desiredSlots($rules, $today, $until, $now);
        /** @var array<int, int|null> $trainers */
        $trainers = $rules->pluck('trainer_id', 'id')->all();

        return DB::transaction(function () use ($desired, $trainers, $now, $until): SlotGenerationResult {
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

            $replaced = self::replacedKeys($now, $until);

            $result = new SlotGenerationResult;

            foreach ($desired as $key => $wanted) {
                $slot = $existing[$key] ?? null;

                if ($slot === null) {
                    // The bulk editor moved this rule slot elsewhere (prompt 15A).
                    if (isset($replaced[$key])) {
                        $result->kept++;

                        continue;
                    }

                    $created = new Slot($wanted);
                    $created->source = SlotSource::Rule;
                    $created->trainer_id = $trainers[$wanted['schedule_rule_id'] ?? 0] ?? null;
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

    /**
     * Rule starts that a moved slot replaced (prompt 15A), as keys.
     *
     * @return array<string, true>
     */
    public static function replacedKeys(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $keys = [];

        foreach (Slot::query()
            ->whereNotNull('replaces_starts_at')
            ->where('replaces_starts_at', '>', $from->utc())
            ->where('replaces_starts_at', '<', $until->utc())
            ->pluck('replaces_starts_at') as $startsAt) {
            $keys[self::key($startsAt instanceof CarbonImmutable ? $startsAt : CarbonImmutable::parse((string) $startsAt, 'UTC'))] = true;
        }

        return $keys;
    }

    public static function key(CarbonImmutable $startsAt): string
    {
        return $startsAt->utc()->format('Y-m-d H:i:s');
    }
}
