<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RideEvent;
use App\Models\RideEventTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RideEventTier>
 */
class RideEventTierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ride_event_id' => RideEvent::factory(),
            'name' => 'Per person',
            'price_minor' => 1_000_000,
            'seats_per_occurrence' => null,
            'sort' => 0,
        ];
    }
}
