<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The four products from PLAN §2. Prices in paisa (PKR × 100). Existing
 * products (matched by slug) are left untouched.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $bring = [
            'Full-length trousers (jeans or riding trousers)',
            'Closed shoes or boots with a small heel',
            'A water bottle',
            'Sunscreen in summer, a warm layer in winter',
        ];

        $included = [
            'Your own horse for the session',
            'Groundwork, grooming and tacking up with your trainer',
            'A guided ride, mostly trail (arena work on request)',
            'Riding helmet if you do not have your own',
            'Untacking, massage and stretch to finish',
        ];

        $products = [
            [
                'slug' => 'single-session',
                'type' => ProductType::Single,
                'category' => ProductCategory::Lessons,
                'name' => 'Single session',
                'price_minor' => 1_000_000,
                'sessions_count' => 1,
                'validity_days' => null,
                'is_featured' => false,
                'sort' => 10,
                'short_description' => 'One 2-hour session: bring your horse in, groom, tack up and ride the trail with your trainer.',
                'long_description' => "The full experience in one morning or afternoon. You start on the ground, meeting your horse in the paddock and learning how to approach him, then groom and tack up before a guided ride.\n\nMost riders finish their first session already looking forward to the next.",
                'who_for' => 'Complete beginners, curious first-timers and anyone who wants to try before committing to a package.',
                'group_size' => 'Up to 6 riders',
                'cover_image' => 'ride.jpg',
                'cover_image_alt' => 'A rider on a white horse crossing a mountain river',
                'good_to_know' => ['No experience needed.'],
            ],
            [
                'slug' => 'private-session',
                'type' => ProductType::Private,
                'category' => ProductCategory::Private,
                'name' => 'Private session',
                'price_minor' => 1_500_000,
                'sessions_count' => 1,
                'validity_days' => null,
                'is_featured' => false,
                'sort' => 20,
                'short_description' => 'Just you, or you and your people: your own trainer and pace on a weekend slot. No other riders join.',
                'long_description' => 'The whole slot is yours. Come alone, as a couple, a family or a small group, and we shape the session around you: more groundwork, more time in the saddle, or arena work on your position and control.',
                'who_for' => 'Individuals, couples, families and small groups who want their own trainer and pace.',
                'group_size' => 'Just your group',
                'cover_image' => 'meet.jpg',
                'cover_image_alt' => 'A man gently touching a grazing white horse',
                'good_to_know' => ['The price is per rider.', 'A private session takes a whole slot, so book early.'],
            ],
            [
                'slug' => '8-class-package',
                'type' => ProductType::Package,
                'category' => ProductCategory::Packages,
                'name' => '8-class package',
                'price_minor' => 6_000_000,
                'sessions_count' => 8,
                'validity_days' => 42,
                'is_featured' => true,
                'sort' => 30,
                'short_description' => 'Eight sessions over six weeks, two a week: from your first time touching a horse to riding the trail with confidence.',
                'long_description' => "Our beginner course. Two sessions a week build your basics fast: handling, grooming and tacking up on your own, then a balanced, relaxed seat on the trail.\n\nBook your first session now and the rest as you go from your account.",
                'who_for' => 'Beginners who want to become confident, independent trail riders.',
                'group_size' => 'Up to 6 riders',
                'cover_image' => 'gallop.jpg',
                'cover_image_alt' => 'A rider galloping a dark horse across a dusty valley below the mountains',
                'good_to_know' => ['Valid for 6 weeks from confirmation.', 'Sessions not used within the validity period expire.'],
            ],
            [
                'slug' => '16-class-package',
                'type' => ProductType::Package,
                'category' => ProductCategory::Packages,
                'name' => '16-class package',
                'price_minor' => 10_000_000,
                'sessions_count' => 16,
                'validity_days' => 70,
                'is_featured' => false,
                'sort' => 40,
                'short_description' => 'Sixteen sessions over ten weeks for riders who want to go further: longer trails, more independence, real horsemanship.',
                'long_description' => 'Everything in the 8-class course, then more: longer trail rides, more work on your own, and the groundwork that gets you ready for an expedition in the mountains.',
                'who_for' => 'Riders who want to progress from beginner to confident trail rider and beyond.',
                'group_size' => 'Up to 6 riders',
                'cover_image' => 'camp.jpg',
                'cover_image_alt' => 'Riders at camp in the golden evening light',
                'good_to_know' => ['Valid for 10 weeks from confirmation.', 'Sessions not used within the validity period expire.'],
            ],
        ];

        foreach ($products as $product) {
            Product::query()->firstOrCreate(['slug' => $product['slug']], [
                ...$product,
                'is_active' => true,
                'duration_minutes' => 120,
                'what_to_bring' => $bring,
                'whats_included' => $included,
            ]);
        }
    }
}
