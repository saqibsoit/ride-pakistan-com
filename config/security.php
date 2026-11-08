<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security hardening (PLAN §7, milestone 10). See docs/SECURITY.md.
|--------------------------------------------------------------------------
*/

return [

    // Strict-Transport-Security, sent on HTTPS responses only.
    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', true),
        'max_age' => (int) env('HSTS_MAX_AGE', 63072000), // two years
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('HSTS_PRELOAD', true),
    ],

    // Features no page uses. Check-in uses the phone's own camera app to
    // open the ticket URL, so no page needs the camera.
    'permissions_policy' => 'accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(), publickey-credentials-get=(self), screen-wake-lock=(), sync-xhr=(), usb=(), xr-spatial-tracking=()',

    'admin' => [
        // Signed out of the panel after this many minutes without a request.
        'idle_minutes' => (int) env('ADMIN_IDLE_MINUTES', 30),

        // Failed panel sign-ins for one team account within the window
        // before the account and every admin get an email.
        'failed_login_alert_threshold' => (int) env('ADMIN_FAILED_LOGIN_ALERT_THRESHOLD', 5),
        'failed_login_window_minutes' => 15,
    ],

    /*
     * Proxies whose X-Forwarded-For / X-Forwarded-Proto are believed
     * (milestone 12). The site sits behind Cloudflare, so without this every
     * visitor would share a Cloudflare IP in the rate limiters, logs and
     * lead attribution, and HTTPS would not be detected. Only Cloudflare's
     * published ranges (https://www.cloudflare.com/ips/) are trusted, so a
     * request that reaches the server directly can't fake its IP.
     * TRUSTED_PROXIES (comma separated) replaces the list; check the
     * ranges again before launch (docs/DEPLOY.md). X-Forwarded-Host is never
     * trusted.
     */
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', implode(',', [
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ])))))),

];
