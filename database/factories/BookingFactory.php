<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Rider;
use App\Models\Slot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slot_id' => Slot::factory(),
            'order_id' => null,
            'rider_id' => Rider::factory(),
            'user_id' => fn (array $attributes): int => Rider::query()->findOrFail((int) $attributes['rider_id'])->user_id,
            'credit_pack_id' => null,
            'ticket_code' => Str::upper(Str::random(16)),
        ];
    }

    public function status(BookingStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'cancelled_at' => $status === BookingStatus::Cancelled ? now() : null,
        ]);
    }
}
