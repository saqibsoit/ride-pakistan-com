<?php

declare(strict_types=1);

namespace App\Support\Tracking;

use Illuminate\Http\Request;

/**
 * The visitor's answer to the cookie banner (`rp_consent`), written by
 * resources/js/tracking.js. Only "granted" allows non-essential tracking
 * (Meta Pixel, GA4, Meta CAPI and the first-touch cookie). No answer
 * means no.
 */
final class Consent
{
    public const COOKIE = 'rp_consent';

    public const GRANTED = 'granted';

    public const DENIED = 'denied';

    public const LIFETIME_DAYS = 180;

    public static function granted(Request $request): bool
    {
        return $request->cookies->get(self::COOKIE) === self::GRANTED;
    }
}
