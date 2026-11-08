<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content-Security-Policy (PLAN §7)
|--------------------------------------------------------------------------
|
| App\Support\Csp\PolicyBuilder builds the header from this file and
| App\Http\Middleware\AddSecurityHeaders sends it on every web and admin
| response (cached pages included: the middleware runs outside the page
| cache). Scripts are allowed by the per-request nonce plus
| 'strict-dynamic' (SetCspNonce, @nonce, Vite/Livewire/Filament), never by
| 'unsafe-inline'.
|
| Two contexts:
|  - public (site, account, checkout, check-in): Alpine and Livewire CSP
|    builds, so no 'unsafe-eval' and no inline style attributes;
|  - admin (the Filament panel): Filament evaluates Alpine expressions and
|    uses inline style attributes, so it adds 'unsafe-eval' and
|    'unsafe-inline' styles. The panel is behind MFA and is never framed.
|
| Every third-party origin is listed under `third_party` with where and
| why. Entries with `requires` are added only when that integration is
| configured AND the visitor accepted the cookie banner (rp_consent).
|
*/

return [

    'enabled' => (bool) env('CSP_ENABLED', true),

    // Staging trial: send Content-Security-Policy-Report-Only instead.
    'report_only' => (bool) env('CSP_REPORT_ONLY', false),

    'base' => [
        'default-src' => ["'self'"],
        'script-src' => [],                 // nonce + 'strict-dynamic' added by the builder
        'style-src' => ["'self'"],          // + nonce
        'img-src' => ["'self'", 'data:', 'blob:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'frame-src' => ["'none'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
        'base-uri' => ["'none'"],
        'object-src' => ["'none'"],
        'manifest-src' => ["'self'"],
        'worker-src' => ["'self'"],
    ],

    'admin' => [
        'script-src' => ["'unsafe-eval'"],
        // Inline style attributes; with 'unsafe-inline' present the nonce
        // is left out of style-src (a nonce would disable 'unsafe-inline').
        'style-src' => ["'unsafe-inline'"],
    ],

    // Violation reports (POST /csp-report, rate limited, logged to the
    // `csp` log channel).
    'report' => [
        'enabled' => (bool) env('CSP_REPORTING', true),
        'max_bytes' => 16 * 1024,
    ],

    'third_party' => [
        'turnstile' => [
            'purpose' => 'Cloudflare Turnstile bot check on /login and the lead forms (strictly necessary, no consent needed). Lead forms load it only when both keys are set.',
            'pages' => ['/login', '/contact', '/prices'],
            'script-src' => ['https://challenges.cloudflare.com'],
            'frame-src' => ['https://challenges.cloudflare.com'],
            'connect-src' => ['https://challenges.cloudflare.com'],
        ],
        'meta_pixel' => [
            'purpose' => 'Meta Pixel (ad measurement). Loaded by resources/js/tracking.js with the page nonce, only after the visitor accepts the cookie banner and only when META_PIXEL_ID is set.',
            'pages' => ['all public pages'],
            'requires' => 'meta_pixel',
            'script-src' => ['https://connect.facebook.net'],
            'img-src' => ['https://www.facebook.com'],
            'connect-src' => ['https://www.facebook.com', 'https://connect.facebook.net'],
        ],
        'google_analytics' => [
            'purpose' => 'Google Analytics 4 (gtag.js). Loaded by resources/js/tracking.js with the page nonce, only after consent and only when GA4_MEASUREMENT_ID is set.',
            'pages' => ['all public pages'],
            'requires' => 'ga4',
            'script-src' => ['https://www.googletagmanager.com'],
            'img-src' => ['https://www.google-analytics.com', 'https://www.googletagmanager.com'],
            'connect-src' => ['https://www.google-analytics.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://www.googletagmanager.com'],
        ],
        'meta_capi' => [
            'purpose' => 'Meta Conversions API (server to graph.facebook.com, queued). No browser request, so no CSP source; listed so the register is complete. Only for visitors who consented.',
            'pages' => [],
        ],
        'stripe_checkout' => [
            'purpose' => 'Stripe-hosted Checkout (card payment). A full-page redirect after a POST: no Stripe script, frame or request runs on our pages. form-action covers the redirect.',
            'pages' => ['/checkout/{reference}'],
            'form-action' => ['https://checkout.stripe.com'],
        ],
    ],

];
