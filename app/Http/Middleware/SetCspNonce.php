<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * A fresh CSP nonce for every request (PLAN §7). Vite, Livewire and
 * Filament pick it up from Vite::cspNonce(); our own tags use `@nonce`.
 * Cached pages get the current request's nonce back through
 * App\Support\Csp\NonceReplacer. The header itself is enforced in
 * milestone 10 (config/csp.php is the register it is built from).
 */
final class SetCspNonce
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        return $next($request);
    }
}
