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
        'contact.google_review_url' => '',
        'contact.expeditions_url' => env('SITE_EXPEDITIONS_URL', 'https://ridepakistan.co'),

        // Booking rules (PLAN §2 open decisions)
        'booking.riders_per_slot' => 6,
        'booking.transfer_hold_hours' => 24,
        'booking.transfer_min_notice_hours' => 12,
        'booking.cancellation_cutoff_hours' => 48,
        'booking.selling_fast_threshold' => 2,
        'booking.slot_horizon_weeks' => 8,
        'booking.card_hold_minutes' => 30,
        'booking.min_notice_hours' => 0,
        'booking.waitlist_offer_hours' => 2,
        'booking.min_rider_age' => null,
        'booking.checkin_opens_minutes' => 120,
        'booking.arrival_minutes' => null,
        'booking.map_pin_url' => env('BOOKING_MAP_PIN_URL', ''),

        // Policies. ":cutoff" and ":min_age" are replaced when shown.
        // Only confirmed or neutral wording here: unconfirmed rules (helmets,
        // arrival time, weather, minors, photography, transport) are listed
        // in docs/OPEN-QUESTIONS.md until the business confirms them.
        'policies.riding_rules' => [
            'Wear full-length trousers and closed shoes or boots with a small heel.',
            'Follow your trainer’s instructions around the horses at all times.',
        ],
        'policies.booking' => [
            'Cancel or reschedule up to :cutoff hours before your slot.',
            'We send the exact location pin when your booking is confirmed.',
        ],
        'policies.refund' => '',

        // Bank transfer (shown only on the checkout transfer page, milestone 7)
        'bank.account_name' => env('BANK_ACCOUNT_NAME', ''),
        'bank.name' => env('BANK_NAME', ''),
        'bank.iban' => env('BANK_IBAN', ''),
        'bank.swift' => env('BANK_SWIFT', ''),
        'bank.country' => env('BANK_COUNTRY', ''),
        'bank.address' => env('BANK_ADDRESS', ''),

        // Privacy: account deletion (PLAN §7). Deleted accounts are
        // anonymised after the grace period, unless they have a paid order
        // younger than the retention period (records kept for tax).
        'leads.follow_up_days' => 2,

        // Gift vouchers and referrals (milestone 10e). Amounts in whole rupees.
        'vouchers.validity_months' => 12,
        'vouchers.min_amount_pkr' => 1000,
        'vouchers.max_amount_pkr' => 500000,
        'referrals.friend_discount_pkr' => 1000,
        'referrals.referrer_credit_pkr' => 1000,

        'privacy.deletion_grace_days' => 30,
        'privacy.order_retention_years' => 6,
        'privacy.transfer_proof_retention_days' => 90,

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

        // Weather alerts (milestone 10f). The coordinates are private and
        // come from .env until an admin saves them. Thresholds apply to each
        // forecast hour of a session.
        'weather.enabled' => false,
        'weather.latitude' => env('WEATHER_LAT'),
        'weather.longitude' => env('WEATHER_LNG'),
        'weather.rain_probability' => 70,
        'weather.precipitation_mm' => 2.0,
        'weather.wind_kmh' => 40,
        'weather.heat_c' => 38,
        'weather.cold_c' => 5,
        'weather.thunder_codes' => ['95', '96', '99'],
        'weather.recipients' => ['admin'],
        // Neutral on purpose: the bad-weather policy is still open (OPEN-QUESTIONS 3.2).
        'weather.template_update' => "Here's a weather update for your session on :session (Pakistan time).\n\nThe forecast for the session isn't good. We're keeping an eye on it and will email you again if anything changes.",
        'weather.template_moved' => "Because of the weather forecast, your session on :session (Pakistan time) is moving.\n\nWe'll email you to confirm your new session.",
        'weather.template_cancelled' => 'The weather forecast for this session makes riding unsafe.',
    ],

];
