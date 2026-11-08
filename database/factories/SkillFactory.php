<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Skill '.fake()->unique()->numberBetween(1, 99999),
            'description' => fake()->sentence(),
            'sort' => fake()->numberBetween(100, 900),
            'is_active' => true,
        ];
    }
}
