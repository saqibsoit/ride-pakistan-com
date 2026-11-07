<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Booking display rules
    |--------------------------------------------------------------------------
    |
    | "Selling fast" shows when seats left are at or below this number and
    | above zero (docs/reference/DESIGN-REFERENCES.md). Becomes a setting in
    | milestone 3.
    |
    */

    'selling_fast_threshold' => (int) env('BOOKING_SELLING_FAST_THRESHOLD', 2),

];
