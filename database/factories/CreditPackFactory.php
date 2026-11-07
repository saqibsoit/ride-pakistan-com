<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\CreditPack;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditPack>
 */
class CreditPackFactory extends Factory
{
    /**
     * An 8-credit pack valid for 42 days (PLAN §2), from a paid order.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->status(OrderStatus::Paid),
            'user_id' => fn (array $attributes): int => Order::query()->findOrFail((int) $attributes['order_id'])->user_id,
            'credits_total' => 8,
            'valid_from' => now(),
            'expires_at' => now()->addDays(42),
        ];
    }
}
