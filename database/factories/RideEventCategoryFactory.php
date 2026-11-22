<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RideEventCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RideEventCategory>
 */
class RideEventCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word()).' '.fake()->unique()->numberBetween(1, 99999);

        return ['slug' => Str::slug($name), 'name' => $name, 'sort' => 0, 'is_active' => true];
    }
}
