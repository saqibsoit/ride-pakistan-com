<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadNote>
 */
class LeadNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'author_id' => User::factory()->admin(),
            'body' => fake()->sentence(),
        ];
    }
}
