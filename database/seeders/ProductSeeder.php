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
            'Full-length trousers',
            'Closed shoes or boots with a small heel',
        ];

        // Session content as listed in PLAN §2. Tack is an open question
        // (docs/OPEN-QUESTIONS.md), so it is not promised here. Riders bring
        // their own helmet: the session page adds the policies.helmet_notice
        // setting to "What to bring" (prompt 14).
        $included = [
            'Bringing your horse in from the paddock',
            'Grooming and tacking up with your trainer',
            'A ride, mostly on the trail (arena work on request)',
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
                'long_description' => 'The full experience in one morning or afternoon. You start on the ground, meeting your horse in the paddock and learning how to approach him, then groom and tack up before your ride.',
                'who_for' => 'Complete beginners and anyone who wants to try a session before choosing a package.',
                'group_size' => null, // shown as "Up to {riders per slot} riders"
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
                'long_description' => 'The whole slot is yours. Come alone, as a couple, a family or a small group, at your own pace. Arena work is available on request.',
                'who_for' => 'Individuals, couples, families and small groups who want their own trainer and pace.',
                'group_size' => 'Just your group',
                'cover_image' => 'meet.jpg',
                'cover_image_alt' => 'A man gently touching a grazing white horse',
                'good_to_know' => ['The price is per rider.', 'A private session takes a whole slot.'],
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
                'short_description' => 'Eight sessions, two a week, valid for six weeks from confirmation.',
                'long_description' => 'Eight full sessions: bring your horse in, groom, tack up, ride, untack and cool down. Book your sessions as you go.',
                'who_for' => 'Riders who want to come back regularly and build on each session.',
                'group_size' => null,
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
                'short_description' => 'Sixteen sessions, two a week, valid for ten weeks from confirmation.',
                'long_description' => 'Sixteen full sessions: bring your horse in, groom, tack up, ride, untack and cool down. Book your sessions as you go.',
                'who_for' => 'Riders who want to ride regularly over a longer period.',
                'group_size' => null,
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
