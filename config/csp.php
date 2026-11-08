<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content-Security-Policy allow-list (PLAN §7)
|--------------------------------------------------------------------------
|
| Every third-party origin the site loads, with where and why. The policy
| itself is enforced in milestone 10 (security hardening); until then this
| file is the register that milestone builds the header from.
|
*/

return [

    'third_party' => [
        'turnstile' => [
            'purpose' => 'Cloudflare Turnstile bot check on /login (strictly necessary, no consent needed).',
            'pages' => ['/login'],
            'script-src' => ['https://challenges.cloudflare.com'],
            'frame-src' => ['https://challenges.cloudflare.com'],
            'connect-src' => ['https://challenges.cloudflare.com'],
        ],
    ],

];
