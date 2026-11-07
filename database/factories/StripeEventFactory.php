<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StripeEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StripeEvent>
 */
class StripeEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => 'evt_'.Str::random(24),
            'type' => 'checkout.session.completed',
            'processed_at' => null,
        ];
    }
}
