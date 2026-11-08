<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\BankTransfer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankTransfer>
 */
class BankTransferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->status(OrderStatus::AwaitingVerification)->state(['payment_method' => PaymentMethod::BankTransfer]),
            'screenshot_path' => 'transfers/'.Str::random(40).'.jpg',
            'transaction_ref' => null,
            'submitted_at' => now(),
        ];
    }
}
