<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\ReviewStatus;
use App\Models\Booking;
use App\Models\Product;
use App\Models\Review;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A past session at an unusual minute, so it never clashes with generated slots.
            'booking_id' => Booking::factory()->status(BookingStatus::Attended)->state(fn (): array => [
                'slot_id' => Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->subDays(fake()->unique()->numberBetween(30, 5000))->setTime(11, 17)),
            ]),
            'user_id' => fn (array $a): int => Booking::query()->findOrFail((int) $a['booking_id'])->user_id,
            'slot_id' => fn (array $a): int => Booking::query()->findOrFail((int) $a['booking_id'])->slot_id,
            'product_id' => null,
            'rating' => 5,
            'body' => fake()->sentences(2, true),
            'display_name' => fake()->firstName().' '.fake()->randomLetter().'.',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => ReviewStatus::Approved]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => ['product_id' => $product->id]);
    }
}
