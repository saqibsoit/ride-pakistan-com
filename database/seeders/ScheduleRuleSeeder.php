<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SettingKey;
use App\Models\ScheduleRule;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

/**
 * PLAN §2: Saturday and Sunday, 08:00–10:00 and 15:00–17:00 Asia/Karachi,
 * capacity from the "riders per slot" setting.
 */
class ScheduleRuleSeeder extends Seeder
{
    public function run(Settings $settings): void
    {
        $capacity = $settings->int(SettingKey::RidersPerSlot) ?? 6;

        foreach ([CarbonInterface::SATURDAY, CarbonInterface::SUNDAY] as $day) {
            foreach ([['08:00:00', '10:00:00'], ['15:00:00', '17:00:00']] as [$start, $end]) {
                ScheduleRule::query()->firstOrCreate(
                    ['day_of_week' => $day, 'start_time' => $start],
                    ['end_time' => $end, 'capacity' => $capacity, 'is_active' => true],
                );
            }
        }
    }
}
