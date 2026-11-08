<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScheduleRule;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleRule>
 */
class ScheduleRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'day_of_week' => CarbonInterface::SATURDAY,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'capacity' => 6,
            'valid_from' => null,
            'valid_to' => null,
            'is_active' => true,
        ];
    }
}
