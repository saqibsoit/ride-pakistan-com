<?php

declare(strict_types=1);

namespace App\Support\Csp;

use Illuminate\Support\Facades\Vite;
use Spatie\ResponseCache\Replacers\Replacer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full-page cache and CSP nonces: the nonce of the request that filled the
 * cache is swapped for a placeholder, and every cached hit gets its own
 * request's nonce. Without this every visitor would share one nonce.
 */
final class NonceReplacer implements Replacer
{
    public const PLACEHOLDER = '<laravel-responsecache-csp-nonce-here>';

    public function prepareResponseToCache(Response $response): void
    {
        $nonce = Vite::cspNonce();
        $content = $response->getContent();

        if (! is_string($nonce) || $nonce === '' || ! $content) {
            return;
        }

        $response->setContent(str_replace('nonce="'.$nonce.'"', 'nonce="'.self::PLACEHOLDER.'"', $content));
    }

    public function replaceInCachedResponse(Response $response): void
    {
        $nonce = Vite::cspNonce();
        $content = $response->getContent();

        if (! is_string($nonce) || $nonce === '' || ! $content) {
            return;
        }

        $response->setContent(str_replace(self::PLACEHOLDER, $nonce, $content));
    }
}
