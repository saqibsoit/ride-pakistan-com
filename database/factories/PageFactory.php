<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(implode(' ', (array) $this->faker->unique()->words(3)));

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'meta_description' => $this->faker->sentence(15),
            'answer' => $this->faker->sentence(20),
            'body' => [
                ['type' => 'text', 'data' => ['heading' => 'Section', 'content' => '<p>'.$this->faker->paragraph().'</p>']],
            ],
            'is_published' => true,
            'is_indexable' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(['is_published' => false]);
    }
}
