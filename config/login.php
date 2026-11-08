<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Customer sign-in with one-time codes (PLAN §6, §7)
|--------------------------------------------------------------------------
|
| Security parameters for the passwordless login. Kept in config (not
| admin settings) so they can only change with a deploy.
|
*/

return [

    'code' => [
        'length' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
    ],

    // Requests for a code. Per email bounds guessing to
    // max_attempts × per_email_per_day guesses a day for one address.
    'request_limits' => [
        'per_email_per_10_minutes' => 3,
        'per_email_per_day' => 10,
        'per_ip_per_hour' => 10,
    ],

    // Code submissions, on top of the per-code attempt limit.
    'verify_limits' => [
        'per_ip_per_minute' => 10,
    ],

];
