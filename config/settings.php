<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Setting defaults
|--------------------------------------------------------------------------
|
| Fallback values for the admin-editable settings (App\Enums\SettingKey).
| A value saved in the settings table always wins. Anything that differs
| per environment, or that we must not hard-code (bank details), comes from
| .env. Keys mirror SettingKey values.
|
*/

return [

    'defaults' => [

        // Business and brand
        'business.name' => 'Ride Pakistan',
        'business.parent_company' => 'Destination Pakistan',
        'business.trainer_name' => 'Omar Saleem',
        'business.location' => 'Islamabad',
        'business.tagline' => 'Weekend horsemanship and trail riding in Islamabad.',

        // Contact and links
        'contact.email' => env('SITE_EMAIL', 'hello@ridepakistan.co'),
        'contact.instagram_url' => env('SITE_INSTAGRAM_URL', 'https://www.instagram.com/ridepakistan/'),
        'contact.instagram_dm_url' => env('SITE_INSTAGRAM_DM_URL', 'https://ig.me/m/ridepakistan'),
        'contact.expeditions_url' => env('SITE_EXPEDITIONS_URL', 'https://ridepakistan.co'),

        // Schedule (plain-language summary; slots come from schedule rules)
        'schedule.summary' => 'Every Saturday and Sunday, 8–10 am and 3–5 pm. Times shift slightly with the seasons; we confirm your slot when you book.',

        // Booking rules (PLAN §2 open decisions)
        'booking.riders_per_slot' => 6,
        'booking.transfer_hold_hours' => 24,
        'booking.cancellation_cutoff_hours' => 48,
        'booking.selling_fast_threshold' => 2,
        'booking.min_rider_age' => null,
        'booking.map_pin_url' => env('BOOKING_MAP_PIN_URL', ''),

        // Policies. ":cutoff" and ":min_age" are replaced when shown.
        'policies.riding_rules' => [
            'A riding helmet is required at all times in the saddle. We provide one if you do not have your own.',
            'Wear full-length trousers and closed shoes or boots with a small heel.',
            'Arrive 15 minutes before your slot so you start on time.',
            'Follow your trainer’s instructions around the horses at all times.',
        ],
        'policies.booking' => [
            'Cancel or reschedule up to :cutoff hours before your slot.',
            'If weather makes riding unsafe, we move your session to another slot at no cost.',
            'Riders under 18 need a parent or guardian’s consent.',
            'We sometimes take photos and video. Tell us on the day if you would rather not be photographed.',
            'Transport is not provided. We send the exact location pin when your booking is confirmed.',
        ],
        'policies.refund' => '',

        // Bank transfer (shown only on the checkout transfer page, milestone 7)
        'bank.account_name' => env('BANK_ACCOUNT_NAME', ''),
        'bank.name' => env('BANK_NAME', ''),
        'bank.iban' => env('BANK_IBAN', ''),
        'bank.swift' => env('BANK_SWIFT', ''),
        'bank.country' => env('BANK_COUNTRY', ''),
        'bank.address' => env('BANK_ADDRESS', ''),

        // SEO
        'seo.default_title' => 'Ride Pakistan | Weekend horse riding in Islamabad',
        'seo.default_description' => 'Weekend horsemanship and trail riding sessions in Islamabad with trainer Omar Saleem. Every Saturday and Sunday. No experience needed.',
        // Reputable AI crawlers named in robots.txt (decision: allowed; PLAN §8).
        'seo.ai_crawlers' => [
            'GPTBot',
            'OAI-SearchBot',
            'ChatGPT-User',
            'ClaudeBot',
            'Claude-SearchBot',
            'Claude-User',
            'PerplexityBot',
            'Perplexity-User',
            'Google-Extended',
            'Applebot-Extended',
        ],
    ],

];
