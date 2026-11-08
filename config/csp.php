<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content-Security-Policy allow-list (PLAN §7)
|--------------------------------------------------------------------------
|
| Every third-party origin the site loads, with where and why. The policy
| itself is enforced in milestone 10 (security hardening); until then this
| file is the register that milestone builds the header from. Every
| script already carries the per-request nonce (SetCspNonce, @nonce,
| Vite/Livewire), and the vendor scripts are injected with that nonce, so
| the policy can use 'nonce-…' 'strict-dynamic'.
|
*/

return [

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
            'script-src' => ['https://connect.facebook.net'],
            'img-src' => ['https://www.facebook.com'],
            'connect-src' => ['https://www.facebook.com', 'https://connect.facebook.net'],
        ],
        'google_analytics' => [
            'purpose' => 'Google Analytics 4 (gtag.js). Loaded by resources/js/tracking.js with the page nonce, only after consent and only when GA4_MEASUREMENT_ID is set.',
            'pages' => ['all public pages'],
            'script-src' => ['https://www.googletagmanager.com'],
            'img-src' => ['https://www.google-analytics.com', 'https://www.googletagmanager.com'],
            'connect-src' => ['https://www.google-analytics.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://www.googletagmanager.com'],
        ],
        'meta_capi' => [
            'purpose' => 'Meta Conversions API (server to graph.facebook.com, queued). No browser request, so no CSP source; listed so the register is complete. Only for visitors who consented.',
            'pages' => [],
        ],
        'stripe_checkout' => [
            'purpose' => 'Stripe-hosted Checkout (card payment). A full-page redirect after a POST: no Stripe script, frame or request runs on our pages.',
            'pages' => ['/checkout/{reference}'],
            'form-action' => ['https://checkout.stripe.com'],
        ],
    ],

];
