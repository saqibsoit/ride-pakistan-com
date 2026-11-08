<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Resend (resend/resend-laravel), the production mail transport
|--------------------------------------------------------------------------
|
| Only the API key is used. The package's webhook route (POST
| /resend/webhook) is off: nothing here consumes Resend webhooks, and
| without a signing secret the route would accept unsigned requests. Turn
| it on only together with RESEND_WEBHOOK_SECRET and a CSP/rate-limit
| review (docs/DEPLOY.md).
|
*/

return [

    'api_key' => env('RESEND_API_KEY'),

    'routes' => (bool) env('RESEND_ROUTES', false),

    'domain' => env('RESEND_DOMAIN'),

    'path' => env('RESEND_PATH', 'resend'),

    'webhook' => [
        'secret' => env('RESEND_WEBHOOK_SECRET'),
        'tolerance' => (int) env('RESEND_WEBHOOK_TOLERANCE', 300),
    ],

    'middleware' => [],

];
