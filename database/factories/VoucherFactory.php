<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Vouchers\GenerateVoucherCode;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voucher>
 */
class VoucherFactory extends Factory
{
    /**
     * An admin-issued PKR 10,000 voucher valid for 12 months.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => GenerateVoucherCode::random(),
            'initial_minor' => 1_000_000,
            'balance_minor' => 1_000_000,
            'currency' => 'PKR',
            'recipient_name' => fake()->name(),
            'recipient_email' => fake()->unique()->safeEmail(),
            'issue_reason' => 'Test voucher',
            'expires_at' => now()->addMonths(12),
        ];
    }
}
