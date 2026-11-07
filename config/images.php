<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Responsive image variants
    |--------------------------------------------------------------------------
    |
    | Every uploaded or shipped photo gets AVIF and WebP variants at these
    | widths (never upscaled). <x-img> builds its srcset from the sidecar
    | manifest written next to the variants.
    |
    */

    'widths' => [480, 768, 1200, 1800],

    'formats' => ['avif', 'webp'],

    'quality' => [
        'avif' => (int) env('IMAGES_AVIF_QUALITY', 40),
        'webp' => (int) env('IMAGES_WEBP_QUALITY', 75),
    ],

    // Folder (relative to the source image's folder) that holds variants.
    'variants_dir' => '_variants',

    // Source files the artisan command picks up.
    'extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    // Disk used when <x-img> is given a plain path.
    'default_disk' => 'site',

];
