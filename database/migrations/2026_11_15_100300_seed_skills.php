<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The default skill checklist (milestone 10d), seeded once by key. Admins
 * rename, reorder, add or switch off skills afterwards; rows that already
 * exist are never touched.
 */
return new class extends Migration
{
    /**
     * @return list<array{key: string, name: string, description: string}>
     */
    public static function skills(): array
    {
        return [
            ['key' => 'approach_lead', 'name' => 'Approach and lead a horse', 'description' => 'Walks up to a horse calmly, puts on the head collar and leads it safely.'],
            ['key' => 'grooming', 'name' => 'Grooming', 'description' => 'Brushes the horse and picks out its hooves with help nearby.'],
            ['key' => 'tacking_up', 'name' => 'Tacking up', 'description' => 'Puts on the saddle and bridle and checks the girth.'],
            ['key' => 'mounting_dismounting', 'name' => 'Mounting and dismounting', 'description' => 'Gets on and off safely from the mounting block.'],
            ['key' => 'walk_halt_steer', 'name' => 'Walk, halt and steer', 'description' => 'Asks the horse to walk on, stop and turn on their own.'],
            ['key' => 'rising_trot', 'name' => 'Rising trot', 'description' => 'Rises to the trot in rhythm and keeps their balance.'],
            ['key' => 'trail_varied_ground', 'name' => 'Trail riding on varied ground', 'description' => 'Rides the trail up and down slopes and over uneven ground.'],
            ['key' => 'canter', 'name' => 'Canter', 'description' => 'Asks for canter, keeps it going and comes back to trot.'],
        ];
    }

    public function up(): void
    {
        $now = now();
        $existing = DB::table('skills')->whereNotNull('key')->pluck('key')->all();

        foreach (self::skills() as $sort => $skill) {
            if (in_array($skill['key'], $existing, true)) {
                continue;
            }

            DB::table('skills')->insert($skill + [
                'sort' => ($sort + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $keys = array_column(self::skills(), 'key');
        $used = DB::table('rider_skills')->join('skills', 'skills.id', '=', 'rider_skills.skill_id')->whereIn('skills.key', $keys)->exists();

        if (! $used) {
            DB::table('skills')->whereIn('key', $keys)->delete();
        }
    }
};
