<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Slot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingHold>
 */
class BookingHoldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slot_id' => Slot::factory(),
            'order_id' => Order::factory(),
            'seats' => 1,
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
