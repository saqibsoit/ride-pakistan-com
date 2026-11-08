<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HorseLevel;
use App\Models\Horse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Horse>
 */
class HorseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst((string) $this->faker->unique()->firstName()),
            'temperament_notes' => 'Calm, good with beginners.',
            'suitable_levels' => [HorseLevel::Beginner, HorseLevel::Intermediate, HorseLevel::Confident],
            'max_sessions_per_day' => 2,
            'is_active' => true,
        ];
    }

    /** @param  list<HorseLevel>  $levels */
    public function levels(array $levels): static
    {
        return $this->state(['suitable_levels' => $levels]);
    }

    public function retired(): static
    {
        return $this->afterMaking(function (Horse $horse): void {
            $horse->retired_at = now()->toImmutable();
            $horse->is_active = false;
        });
    }
}
