<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadInterest;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'phone' => '+92300'.$this->faker->numerify('#######'),
            'email' => $this->faker->safeEmail(),
            'interest' => LeadInterest::Single,
            'message' => $this->faker->sentence(),
            'status' => LeadStatus::New,
            'source' => LeadSource::ContactForm,
        ];
    }

    public function instagramDm(): static
    {
        return $this->state(fn (): array => [
            'source' => LeadSource::InstagramDm,
            'instagram_handle' => 'rider.'.$this->faker->numerify('####'),
            'email' => null,
        ]);
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status])
            ->afterMaking(function (Lead $lead) use ($status): void {
                if ($status === LeadStatus::Lost) {
                    $lead->lost_reason = 'Chose another stable';
                }
            });
    }
}
