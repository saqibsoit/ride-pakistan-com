<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    /**
     * 10% off every product, no limits.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'SAVE'.Str::upper(Str::random(6)),
            'discount_type' => DiscountType::Percent,
            'percent' => 10,
        ];
    }

    public function fixed(int $amountMinor): static
    {
        return $this->state(fn (): array => [
            'discount_type' => DiscountType::Fixed,
            'percent' => null,
            'amount_minor' => $amountMinor,
        ]);
    }
}
