<?php

declare(strict_types=1);

namespace App\Support\Csp;

use App\Support\Tracking\TrackingConfig;

/**
 * Builds the Content-Security-Policy header value from config/csp.php
 * (PLAN §7). Scripts run only with the request's nonce ('strict-dynamic'
 * passes trust to the scripts they load). Consent-gated vendors (Meta
 * Pixel, GA4) are listed only for a visitor who accepted the cookie
 * banner and only when the vendor is configured.
 */
final class PolicyBuilder
{
    public function __construct(private readonly TrackingConfig $tracking) {}

    /**
     * @param  string|null  $hotUrl  the Vite dev server origin (local only)
     * @param  bool  $secure  HTTPS request: also upgrade insecure subresources
     */
    public function build(string $nonce, bool $admin = false, bool $trackingConsent = false, ?string $reportUri = null, ?string $hotUrl = null, bool $secure = false): string
    {
        /** @var array<string, list<string>> $directives */
        $directives = (array) config('csp.base', []);

        $directives['script-src'] = ["'nonce-{$nonce}'", "'strict-dynamic'", ...($directives['script-src'] ?? [])];

        if ($admin) {
            /** @var array<string, list<string>> $extra */
            $extra = (array) config('csp.admin', []);

            foreach ($extra as $directive => $sources) {
                $directives[$directive] = [...($directives[$directive] ?? []), ...$sources];
            }
        }

        // A nonce in style-src switches off 'unsafe-inline', so only add it
        // where inline styles are not allowed anyway.
        if (! in_array("'unsafe-inline'", $directives['style-src'] ?? [], true)) {
            $directives['style-src'] = [...($directives['style-src'] ?? []), "'nonce-{$nonce}'"];
        }

        /** @var array<string, array<string, mixed>> $vendors */
        $vendors = (array) config('csp.third_party', []);

        foreach ($vendors as $vendor) {
            if (! $this->allowed($vendor['requires'] ?? null, $trackingConsent)) {
                continue;
            }

            foreach ($vendor as $directive => $sources) {
                if (is_array($sources) && (str_ends_with((string) $directive, '-src') || $directive === 'form-action')) {
                    /** @var list<string> $sources */
                    $directives[$directive] = [...($directives[$directive] ?? []), ...$sources];
                }
            }
        }

        if ($hotUrl !== null && $hotUrl !== '') {
            $ws = preg_replace('#^http#', 'ws', $hotUrl) ?? $hotUrl;

            foreach (['style-src', 'img-src', 'font-src'] as $directive) {
                $directives[$directive] = [...($directives[$directive] ?? []), $hotUrl];
            }

            $directives['connect-src'] = [...($directives['connect-src'] ?? []), $hotUrl, $ws];
        }

        $parts = [];

        foreach ($directives as $directive => $sources) {
            $sources = array_values(array_unique($sources));

            // 'none' only when nothing else is allowed.
            if (count($sources) > 1) {
                $sources = array_values(array_filter($sources, fn (string $source): bool => $source !== "'none'"));
            }

            $parts[] = $directive.' '.implode(' ', $sources);
        }

        if ($secure) {
            $parts[] = 'upgrade-insecure-requests';
        }

        if ($reportUri !== null) {
            $parts[] = 'report-uri '.$reportUri;
            $parts[] = 'report-to csp';
        }

        return implode('; ', $parts);
    }

    private function allowed(mixed $requires, bool $trackingConsent): bool
    {
        return match ($requires) {
            null => true,
            'meta_pixel' => $trackingConsent && $this->tracking->metaPixelId() !== null,
            'ga4' => $trackingConsent && $this->tracking->ga4Id() !== null,
            default => false,
        };
    }
}
