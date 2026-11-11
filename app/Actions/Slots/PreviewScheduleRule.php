<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Models\ScheduleRule;
use App\Models\Slot;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * What saving a schedule rule would do to the slots (milestone 10a), shown
 * in the rule form before saving. Read-only: compares the slots the
 * active rules produce now with the slots they would produce with this
 * rule as edited, over the slot horizon, and checks each difference
 * against the real slots table (the same rules GenerateSlots applies):
 *
 * - new: a slot will be created;
 * - changed: an empty open rule slot gets a new end time or capacity;
 * - removed: an empty open rule slot goes;
 * - kept: a slot that won't follow the change because it has bookings,
 *   holds or order lines, is closed or cancelled, or is a manual slot.
 */
final class PreviewScheduleRule
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array{day_of_week: int|string|null, start_time: string|null, end_time: string|null, capacity: int|string|null, valid_from?: string|null, valid_to?: string|null, is_active?: bool|null}  $attributes  the form state
     */
    public function handle(array $attributes, ?ScheduleRule $existing = null): ?SchedulePreview
    {
        $candidate = self::candidate($attributes, $existing);

        if ($candidate === null) {
            return null;
        }

        $weeks = max(1, min(GenerateSlots::MAX_WEEKS, $this->settings->int(SettingKey::SlotHorizonWeeks) ?? 8));
        $now = CarbonImmutable::now();
        $from = $now->setTimezone(Slot::displayTimezone())->startOfDay();
        $until = $from->addWeeks($weeks);

        $current = ScheduleRule::query()->active()->orderBy('id')->get();
        $after = $current->reject(fn (ScheduleRule $rule): bool => $existing !== null && $rule->id === $existing->id)->values();

        if ($candidate->is_active) {
            $after->push($candidate);
        }

        $after = $after->sortBy(fn (ScheduleRule $rule): int => $rule->id ?? PHP_INT_MAX)->values();

        $before = GenerateSlots::desiredSlots($current, $from, $until, $now);
        $wanted = GenerateSlots::desiredSlots($after, $from, $until, $now);

        $keys = array_unique([...array_keys($before), ...array_keys($wanted)]);
        $changedKeys = array_values(array_filter($keys, fn (string $key): bool => ! self::same($before[$key] ?? null, $wanted[$key] ?? null)));

        $slots = Slot::query()
            ->withSeatCounts()
            ->where('slots.starts_at', '>', $now)
            ->where('slots.starts_at', '<', $until->utc())
            ->get()
            ->keyBy(fn (Slot $slot): string => GenerateSlots::key($slot->starts_at));

        $inUse = array_flip(Slot::query()->whereKey($slots->modelKeys())->inUse()->pluck('id')->all());

        $replaced = GenerateSlots::replacedKeys($now, $until);

        $preview = new SchedulePreview($weeks);

        sort($changedKeys);

        foreach ($changedKeys as $key) {
            $old = $before[$key] ?? null;
            $new = $wanted[$key] ?? null;
            $slot = $slots->get($key);
            $startsAt = ($new ?? $old)['starts_at'] ?? CarbonImmutable::parse($key, 'UTC');

            if ($slot === null) {
                if (isset($replaced[$key])) {
                    continue; // moved by the bulk editor; the rule won't refill it (prompt 15A)
                }

                if ($new !== null) {
                    $preview->new[] = SchedulePreviewItem::make($startsAt, $new['ends_at'], $new['capacity']);
                }

                continue;
            }

            $fixed = $slot->isManual() || $slot->status !== SlotStatus::Open || isset($inUse[$slot->id]);

            if ($fixed) {
                $preview->kept[] = SchedulePreviewItem::make($slot->starts_at, $slot->ends_at, $slot->capacity, self::reason($slot, isset($inUse[$slot->id])), $slot->seatsBooked());

                continue;
            }

            if ($new === null) {
                $preview->removed[] = SchedulePreviewItem::make($slot->starts_at, $slot->ends_at, $slot->capacity);
            } else {
                $preview->changed[] = SchedulePreviewItem::make($startsAt, $new['ends_at'], $new['capacity'], 'was '.$slot->localEndsAt()->format('H:i').' end, '.$slot->capacity.' riders');
            }
        }

        return $preview;
    }

    /**
     * The rule as the form describes it (unsaved), or null while the form
     * is incomplete.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function candidate(array $attributes, ?ScheduleRule $existing): ?ScheduleRule
    {
        $day = $attributes['day_of_week'] ?? null;
        $start = $attributes['start_time'] ?? null;
        $end = $attributes['end_time'] ?? null;
        $capacity = $attributes['capacity'] ?? null;

        if (! is_numeric($day) || ! is_string($start) || ! is_string($end) || ! is_numeric($capacity)
            || ! preg_match('/^\d{2}:\d{2}/', $start) || ! preg_match('/^\d{2}:\d{2}/', $end) || substr($end, 0, 5) <= substr($start, 0, 5)) {
            return null;
        }

        $rule = new ScheduleRule;
        $rule->forceFill([
            'day_of_week' => (int) $day,
            'start_time' => substr($start, 0, 5).':00',
            'end_time' => substr($end, 0, 5).':00',
            'capacity' => max(1, (int) $capacity),
            'valid_from' => self::date($attributes['valid_from'] ?? null),
            'valid_to' => self::date($attributes['valid_to'] ?? null),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
        ]);

        if ($existing !== null) {
            $rule->id = $existing->id;
        }

        return $rule;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /**
     * @param  array{schedule_rule_id: int|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable, capacity: int}|null  $a
     * @param  array{schedule_rule_id: int|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable, capacity: int}|null  $b
     */
    private static function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a['ends_at']->equalTo($b['ends_at']) && $a['capacity'] === $b['capacity'];
    }

    private static function reason(Slot $slot, bool $inUse): string
    {
        return match (true) {
            $slot->isManual() => 'manual slot',
            $slot->status === SlotStatus::Cancelled => 'cancelled',
            $slot->status === SlotStatus::Closed => 'closed',
            $inUse && $slot->seatsBooked() > 0 => 'has bookings',
            default => 'has a hold or an unpaid order',
        };
    }
}
