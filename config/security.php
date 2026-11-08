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

];
