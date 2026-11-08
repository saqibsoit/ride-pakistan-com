<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuickReplyCategory;
use App\Models\QuickReply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuickReply>
 */
class QuickReplyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => ucfirst(implode(' ', (array) $this->faker->unique()->words(2))),
            'body' => 'Hi {lead_name}, book here: {booking_link}',
            'category' => QuickReplyCategory::Booking,
            'is_active' => true,
        ];
    }
}
