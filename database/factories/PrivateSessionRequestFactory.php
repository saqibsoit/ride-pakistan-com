<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A request three days ahead at 10:00 Pakistan time, for one rider.
 *
 * @extends Factory<PrivateSessionRequest>
 */
class PrivateSessionRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now((string) config('app.display_timezone'))->addDays(3)->setTime(10, 0)->utc();

        return [
            'reference' => 'RQ-'.Str::upper(Str::random(6)),
            'user_id' => User::factory(),
            'product_id' => fn (): int => Product::query()->where('type', ProductType::Private)->value('id')
                ?? Product::factory()->private()->create()->id,
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'riders' => 1,
            'rider_ids' => fn (array $attributes): array => [Rider::factory()->create(['user_id' => $attributes['user_id']])->id],
            'answer_by' => now()->addHours(2),
        ];
    }
}
