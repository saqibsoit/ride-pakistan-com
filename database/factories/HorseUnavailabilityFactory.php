<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UnavailabilityReason;
use App\Models\Horse;
use App\Models\HorseUnavailability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HorseUnavailability>
 */
class HorseUnavailabilityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'horse_id' => Horse::factory(),
            'date' => now()->toImmutable()->format('Y-m-d'),
            'reason' => UnavailabilityReason::Rest,
        ];
    }
}
