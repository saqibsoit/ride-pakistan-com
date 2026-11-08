<?php

declare(strict_types=1);

namespace App\Support\Csp;

use Illuminate\Support\Facades\Vite;

/** The request's CSP nonce (set by SetCspNonce) for our own script tags. */
final class Nonce
{
    public static function value(): ?string
    {
        $nonce = Vite::cspNonce();

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    /** ` nonce="…"`, or nothing outside a web request. */
    public static function attribute(): string
    {
        $nonce = self::value();

        return $nonce === null ? '' : ' nonce="'.e($nonce).'"';
    }
}
