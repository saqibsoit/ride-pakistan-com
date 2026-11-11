<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Slot;
use App\Models\SlotChangeNotice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SlotChangeNotice>
 */
class SlotChangeNoticeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->toImmutable()->addDays(5)->startOfHour();

        return [
            'batch' => (string) Str::uuid(),
            'slot_id' => Slot::factory(),
            'user_id' => User::factory(),
            'old_starts_at' => $start->subHour(),
            'old_ends_at' => $start->addHour(),
            'new_starts_at' => $start,
            'new_ends_at' => $start->addHours(2),
        ];
    }
}
