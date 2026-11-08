<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Card statements show "<account prefix>* <suffix>" (22 characters
        // in total). The full "Ride Pakistan by Destination Pakistan" line
        // goes on the Checkout page and the receipt email.
        'statement_descriptor_suffix' => env('STRIPE_STATEMENT_DESCRIPTOR_SUFFIX', 'RIDE PAKISTAN'),
    ],

    // Cloudflare Turnstile (bot check on public forms). /login: an empty
    // secret passes outside production and fails closed in production.
    // Lead forms use it only when both keys are set (PLAN §7).
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Meta Pixel (browser, after consent) and Conversions API (server, only
    // for visitors who consented). Empty pixel id: both off. Empty token:
    // CAPI off, the Pixel still works.
    'meta' => [
        'pixel_id' => env('META_PIXEL_ID'),
        'capi_token' => env('META_CAPI_TOKEN'),
        'capi_test_code' => env('META_CAPI_TEST_CODE'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
    ],

    // Google Analytics 4 (browser, after consent). Empty id: off.
    'ga4' => [
        'measurement_id' => env('GA4_MEASUREMENT_ID'),
    ],

    // Open-Meteo forecast API (weather alerts, milestone 10f). Free, no key;
    // called from the server only, so it needs no CSP entry.
    'open_meteo' => [
        'url' => env('OPEN_METEO_URL', 'https://api.open-meteo.com/v1/forecast'),
        'timeout' => 10,
    ],

];
