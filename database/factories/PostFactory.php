<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(implode(' ', (array) $this->faker->unique()->words(5)));

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'excerpt' => $this->faker->sentence(20),
            'body' => [
                ['type' => 'text', 'data' => ['heading' => 'Section', 'content' => '<p>'.$this->faker->paragraph().'</p>']],
            ],
            'author_name' => 'Omar Saleem',
            'published_at' => now()->subDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['published_at' => null]);
    }
}
