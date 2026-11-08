<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Booking;
use Illuminate\Support\Facades\URL;

/**
 * The "Leave a review" link for an attended session (milestone 10d): a
 * signed URL valid for 30 days, so the customer can review straight from
 * the thank-you email without signing in. The signature ties it to that
 * one booking.
 */
final class ReviewLink
{
    public const VALID_DAYS = 30;

    public static function for(Booking $booking): string
    {
        return URL::temporarySignedRoute('reviews.create', now()->addDays(self::VALID_DAYS), ['ticket' => $booking->ticket_code]);
    }
}
