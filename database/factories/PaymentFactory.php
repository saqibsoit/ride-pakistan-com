<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => PaymentProvider::Stripe,
            'provider_id' => 'pi_'.Str::random(24),
            'amount_minor' => 1_000_000,
            'currency' => 'PKR',
            'status' => PaymentStatus::Succeeded,
            'raw' => null,
        ];
    }
}
