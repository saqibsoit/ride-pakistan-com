<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SlotStatus;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Slot>
 */
class SlotFactory extends Factory
{
    /**
     * A Saturday 08:00–10:00 Pakistan time slot in the future.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $local = CarbonImmutable::now((string) config('app.display_timezone'))
            ->next(CarbonImmutable::SATURDAY)
            ->addWeeks(fake()->unique()->numberBetween(0, 500))
            ->setTime(8, 0);

        return [
            'schedule_rule_id' => null,
            'starts_at' => $local->utc(),
            'ends_at' => $local->addHours(2)->utc(),
            'capacity' => 6,
        ];
    }

    /** Starting at a local (display timezone) time. */
    public function at(CarbonImmutable $localStart, int $hours = 2): static
    {
        return $this->state(fn (): array => [
            'starts_at' => $localStart->utc(),
            'ends_at' => $localStart->addHours($hours)->utc(),
        ]);
    }

    /** Test set-up only: real code changes status through ChangeSlotStatus. */
    public function status(SlotStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
