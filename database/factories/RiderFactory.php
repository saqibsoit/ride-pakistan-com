<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgeBracket;
use App\Enums\ExperienceLevel;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rider>
 */
class RiderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'age_bracket' => AgeBracket::Adult,
            'experience_level' => ExperienceLevel::None,
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => '+923001234567',
            'notes' => null,
        ];
    }
}
