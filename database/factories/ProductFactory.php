<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(implode(' ', (array) $this->faker->unique()->words(3)));

        return [
            'slug' => Str::slug($name),
            'type' => ProductType::Single,
            'category' => ProductCategory::Lessons,
            'name' => $name,
            'price_minor' => 1_000_000,
            'sessions_count' => 1,
            'validity_days' => null,
            'is_active' => true,
            'is_featured' => false,
            'sort' => 0,
            'short_description' => $this->faker->sentence(12),
            'long_description' => $this->faker->paragraph(),
            'who_for' => $this->faker->sentence(),
            'what_to_bring' => ['Full-length trousers', 'Closed shoes'],
            'whats_included' => ['A trained horse', 'Helmet'],
            'good_to_know' => ['No experience needed'],
            'duration_minutes' => 120,
            'group_size' => 'Up to 6 riders',
        ];
    }

    public function private(int $priceMinor = 1_500_000): static
    {
        return $this->state(fn (): array => [
            'type' => ProductType::Private,
            'price_minor' => $priceMinor,
        ]);
    }

    public function package(int $sessions = 8, int $priceMinor = 6_000_000, int $validityDays = 42): static
    {
        return $this->state([
            'type' => ProductType::Package,
            'category' => ProductCategory::Packages,
            'sessions_count' => $sessions,
            'price_minor' => $priceMinor,
            'validity_days' => $validityDays,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
