<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Slot;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slot_id' => Slot::factory(),
            'user_id' => User::factory(),
            'seats' => 1,
        ];
    }
}
