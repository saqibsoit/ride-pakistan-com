<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Fixed site constants
    |--------------------------------------------------------------------------
    |
    | Editable business details (contact, links, policies) are settings; see
    | config/settings.php and App\Support\Settings.
    |
    */

    // Appended to every page title: "Prices | Ride Pakistan".
    'title_suffix' => ' | Ride Pakistan',

    // Browser UI colour; mirrors --color-earth in resources/css/tokens.css.
    'theme_color' => '#1F140B',

    // How many weeks ahead /schedule lets visitors page.
    'schedule_weeks_ahead' => 8,

    // Search engines may index this environment (robots meta, robots.txt,
    // X-Robots-Tag). Production only unless overridden; staging stays noindex.
    'indexable' => (bool) env('SITE_INDEXABLE', env('APP_ENV') === 'production'),

    // Generated sitemap (App\Actions\Seo\GenerateSitemap), served at /sitemap.xml.
    'sitemap_path' => base_path((string) env('SITEMAP_PATH', 'storage/app/sitemap.xml')),

];
