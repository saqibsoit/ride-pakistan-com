<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question' => rtrim($this->faker->unique()->sentence(6), '.').'?',
            'answer' => $this->faker->paragraph(),
            'topic' => 'Getting started',
            'sort' => 0,
            'is_published' => true,
        ];
    }
}
