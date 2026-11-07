<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\ResponseCache\Facades\ResponseCache;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Content seeders are idempotent (keyed by slug), so they are safe to run
     * again; they never overwrite content edited in the admin.
     */
    public function run(): void
    {
        $this->call([
            ProductSeeder::class,
            ScheduleRuleSeeder::class,
            PageSeeder::class,
            FaqSeeder::class,
            PostSeeder::class,
        ]);

        if (app()->environment('local')) {
            User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => 'password'],
            );
        }

        // Model events are off while seeding, so refresh caches once here.
        ResponseCache::clear();
    }
}
