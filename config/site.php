<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public contact details and brand links
    |--------------------------------------------------------------------------
    |
    | Shown in the header, footer and calls to action. These move to the
    | admin-editable settings table in milestone 3; until then, override them
    | from .env.
    |
    */

    'default_title' => 'Ride Pakistan | Weekend horse riding in Islamabad',

    'default_description' => 'Weekend horsemanship and trail riding sessions in Islamabad with trainer Omar Saleem. Every Saturday and Sunday. No experience needed.',

    'email' => env('SITE_EMAIL', 'hello@ridepakistan.co'),

    'instagram_url' => env('SITE_INSTAGRAM_URL', 'https://www.instagram.com/ridepakistan/'),

    'instagram_dm_url' => env('SITE_INSTAGRAM_DM_URL', 'https://ig.me/m/ridepakistan'),

    'expeditions_url' => env('SITE_EXPEDITIONS_URL', 'https://ridepakistan.co'),

    'parent_company' => 'Destination Pakistan',

    // Browser UI colour; mirrors --color-earth in resources/css/tokens.css.
    'theme_color' => '#1F140B',

];
