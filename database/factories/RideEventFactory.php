<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BalanceDue;
use App\Enums\EventPaymentMode;
use App\Enums\RideEventStatus;
use App\Models\RideEvent;
use App\Models\RideEventTier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A draft full-payment event with one "Per person" tier at PKR 10,000.
 *
 * @extends Factory<RideEvent>
 */
class RideEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = 'Event '.fake()->unique()->numberBetween(1, 999999);

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'summary' => 'A day at the ranch.',
            'description' => 'A full day with the horses.',
            'location_name' => 'Yashwala Ranch, Islamabad',
            'payment_mode' => EventPaymentMode::Full,
            'deposit_percent' => null,
            'non_refundable_percent' => 0,
            'cancellation_cutoff_days' => 7,
            'balance_due' => BalanceDue::OnTheDay,
            'min_per_booking' => 1,
            'max_per_booking' => 10,
            'booking_closes_hours' => 24,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (RideEvent $event): void {
            if (! $event->tiers()->exists()) {
                RideEventTier::factory()->for($event, 'event')->create(['name' => 'Per person', 'price_minor' => 1_000_000]);
            }
        });
    }

    /** Test set-up only: real code publishes through ChangeRideEventStatus. */
    public function published(): static
    {
        return $this->state(fn (): array => ['status' => RideEventStatus::Published]);
    }

    /** A non-refundable deposit of $percent (the Cowboy Camp rule). */
    public function deposit(int $percent = 50): static
    {
        return $this->state(fn (): array => [
            'payment_mode' => EventPaymentMode::Deposit,
            'deposit_percent' => $percent,
            'non_refundable_percent' => $percent,
        ]);
    }
}
