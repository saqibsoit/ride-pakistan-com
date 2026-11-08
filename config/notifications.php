<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Customer notifications (PLAN §6, milestone 8)
|--------------------------------------------------------------------------
|
| Email is always on. WhatsApp (GT360) is wired as a channel but does
| nothing yet: with the flag on, messages are only logged. A real
| provider replaces App\Notifications\Channels\WhatsAppChannel later.
|
*/

return [

    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
    ],

    // Session reminders go out this many hours before the start (a window
    // so a 15-minute scheduler never misses one).
    'reminder' => [
        'hours_before' => 24,
        'window_hours' => 1,
    ],

    // "Your credits expire soon": this many days before the pack expires.
    'credits_expiring_days' => 7,

    // Thank-you emails: between these many hours after the session ended.
    'thank_you' => [
        'after_hours' => 1,
        'until_hours' => 24,
    ],

];
