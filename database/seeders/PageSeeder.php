<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SettingKey;
use App\Models\Page;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * CMS pages. Templated routes get a record for SEO overrides; trainer and
 * expeditions get the copy from the reference page; legal pages are
 * headings-only drafts (unpublished = 404) until final copy is written.
 */
class PageSeeder extends Seeder
{
    public function run(Settings $settings): void
    {
        foreach (['home' => 'Home', 'experiences' => 'Experiences', 'schedule' => 'Schedule', 'prices' => 'Prices', 'faq' => 'FAQ', 'guides' => 'Guides', 'contact' => 'Contact'] as $slug => $title) {
            Page::query()->firstOrCreate(['slug' => $slug], ['title' => $title, 'is_published' => true, 'is_indexable' => true]);
        }

        Page::query()->firstOrCreate(['slug' => 'trainer'], [
            'title' => 'Omar Saleem, your trainer',
            'meta_title' => 'Omar Saleem – horse riding trainer in Islamabad',
            'meta_description' => 'Meet Omar Saleem, Ride Pakistan’s trainer: from a polo family to trail, endurance and cross-country riding built on a horse’s trust.',
            'answer' => 'Omar Saleem leads every Ride Pakistan session in Islamabad. Born into a polo family, he chose trail, endurance and cross-country riding instead, and teaches beginners to ride by first earning their horse’s trust.',
            'is_published' => true,
            'body' => [
                ['type' => 'text', 'data' => ['heading' => 'From polo to the trail', 'content' => "Omar was born into a polo family. His father captained the Pakistan Army polo team and hoped his sons would form a polo team of their own.\n\nOmar chose a different path. He believes competitive equestrian sport turns horses into equipment and breaks the bond between horse and rider. His focus is the older relationship: trail, endurance and cross-country riding, covering new ground together."]],
                ['type' => 'text', 'data' => ['heading' => 'How he teaches', 'content' => 'A horse is a prey animal. He wants to explore, but he’s wary. Once he trusts the rider on his back, he relaxes and becomes brave enough to go anywhere. Every session is built around earning that trust: you bring the horse in, groom and tack up yourself before you ride, and look after him afterwards.']],
                ['type' => 'quote', 'data' => ['text' => 'Win the horse’s trust, and he’ll take you anywhere.']],
                ['type' => 'cta', 'data' => ['label' => 'Book a session with Omar', 'url' => '/book']],
            ],
        ]);

        Page::query()->firstOrCreate(['slug' => 'expeditions'], [
            'title' => 'Riding expeditions',
            'meta_title' => 'Horse riding expeditions in northern Pakistan',
            'meta_description' => 'After weekend lessons in Islamabad, ride the mountains: Ride Pakistan expeditions through the northern valleys.',
            'answer' => 'Our weekend sessions in Islamabad are the first step. When you’re ready, join a Ride Pakistan expedition: multi-day rides through the northern valleys of Pakistan.',
            'is_published' => true,
            'body' => [
                ['type' => 'image', 'data' => ['image' => 'group.jpg', 'alt' => 'A line of riders on horseback raising their fists in front of a mountain']],
                ['type' => 'text', 'data' => ['heading' => 'Learn in Islamabad. Ride the mountains next.', 'content' => 'Expeditions are run on our expeditions site, with routes, dates and prices for each trip. A package of weekend sessions is the best way to get ready.']],
                ['type' => 'cta', 'data' => ['label' => 'Explore expeditions', 'url' => $settings->string(SettingKey::ExpeditionsUrl), 'text' => 'Routes, dates and prices are on ridepakistan.co.']],
            ],
        ]);

        $legal = [
            'privacy' => ['Privacy policy', ['Who we are', 'What we collect', 'How we use your data', 'Payments', 'Cookies and tracking', 'How long we keep data', 'Your rights', 'Contact']],
            'terms' => ['Terms and conditions', ['Bookings', 'Payments', 'Packages and credits', 'Cancellations and rescheduling', 'Weather', 'Safety and riding rules', 'Minors', 'Photography', 'Liability', 'Contact']],
            'refund-policy' => ['Refund policy', ['Single and private sessions', 'Packages', 'Cancellations by us', 'How refunds are paid', 'Contact']],
        ];

        foreach ($legal as $slug => [$title, $headings]) {
            Page::query()->firstOrCreate(['slug' => $slug], [
                'title' => $title,
                'is_published' => false,
                'is_indexable' => true,
                'body' => array_map(fn (string $heading): array => ['type' => 'text', 'data' => ['heading' => $heading, 'content' => '']], $headings),
            ]);
        }
    }
}
