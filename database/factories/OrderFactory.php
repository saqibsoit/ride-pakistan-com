<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Orders\GenerateOrderReference;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => GenerateOrderReference::random(),
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'amount_minor' => 1_000_000,
            'currency' => 'PKR',
        ];
    }

    /** Test set-up only: real code moves status through OrderStateMachine. */
    public function status(OrderStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'paid_at' => $status === OrderStatus::Paid ? now() : null,
        ]);
    }
}
