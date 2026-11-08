<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Vouchers\GenerateVoucherReference;
use App\Enums\VoucherOrderStatus;
use App\Models\User;
use App\Models\VoucherOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoucherOrder>
 */
class VoucherOrderFactory extends Factory
{
    /**
     * A PKR 10,000 voucher for a friend, sent straight away.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => GenerateVoucherReference::random(),
            'user_id' => User::factory(),
            'amount_minor' => 1_000_000,
            'currency' => 'PKR',
            'recipient_name' => fake()->name(),
            'recipient_email' => fake()->unique()->safeEmail(),
            'message' => 'Happy birthday! Enjoy a ride.',
            'expires_at' => now()->addDay(),
        ];
    }

    /** Test set-up only: real code moves status through VoucherOrderStateMachine. */
    public function status(VoucherOrderStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'paid_at' => $status === VoucherOrderStatus::Paid ? now() : null,
        ]);
    }
}
