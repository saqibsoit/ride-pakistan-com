<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderBalance>
 */
class OrderBalanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'amount_minor' => 500_000,
            'currency' => 'PKR',
            'due_at' => now()->addWeeks(2),
            'online_until' => now()->addWeeks(2),
        ];
    }
}
