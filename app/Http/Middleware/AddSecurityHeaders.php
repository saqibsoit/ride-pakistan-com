<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Csp\Nonce;
use App\Support\Csp\PolicyBuilder;
use App\Support\Tracking\Consent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web and admin-panel response (PLAN §7,
 * milestone 10): CSP with the request's nonce, HSTS (HTTPS only),
 * nosniff, Referrer-Policy, Permissions-Policy, frame protection and
 * COOP. Runs outside the full-page cache, so a cached page still gets
 * its own nonce (NonceReplacer) and a policy that matches the visitor's
 * consent. A response that already set its own CSP (the sandboxed
 * transfer proof) keeps it.
 */
final class AddSecurityHeaders
{
    public function __construct(private readonly PolicyBuilder $policy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', (string) config('security.permissions_policy'), false);
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin', false);

        if ($request->isSecure() && config('security.hsts.enabled')) {
            $headers->set('Strict-Transport-Security', self::hsts());
        }

        $this->addCsp($request, $response);

        return $response;
    }

    private function addCsp(Request $request, Response $response): void
    {
        $headers = $response->headers;

        if (! config('csp.enabled')
            || $headers->has('Content-Security-Policy')
            || $headers->has('Content-Security-Policy-Report-Only')) {
            return;
        }

        $nonce = Nonce::value();

        if ($nonce === null) {
            Vite::useCspNonce();
            $nonce = (string) Nonce::value();
        }

        $reportUri = config('csp.report.enabled') && Route::has('csp.report') ? route('csp.report') : null;

        $value = $this->policy->build(
            nonce: $nonce,
            admin: self::isAdmin($request),
            trackingConsent: Consent::granted($request),
            reportUri: $reportUri,
            hotUrl: Vite::isRunningHot() ? self::hotOrigin() : null,
            secure: $request->isSecure(),
        );

        $headers->set(config('csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $value);

        if ($reportUri !== null) {
            $headers->set('Reporting-Endpoints', 'csp="'.$reportUri.'"');
        }
    }

    /** The Filament panel (and anything else under the admin path). */
    public static function isAdmin(Request $request): bool
    {
        if ($request->routeIs('filament.*')) {
            return true;
        }

        $path = trim((string) config('app.admin_path', 'admin'), '/');

        return $path !== '' && ($request->is($path) || $request->is($path.'/*'));
    }

    private static function hsts(): string
    {
        $value = 'max-age='.max(0, (int) config('security.hsts.max_age'));

        if (config('security.hsts.include_subdomains')) {
            $value .= '; includeSubDomains';
        }

        if (config('security.hsts.preload')) {
            $value .= '; preload';
        }

        return $value;
    }

    private static function hotOrigin(): ?string
    {
        $hot = @file_get_contents(public_path('hot'));

        if (! is_string($hot)) {
            return null;
        }

        $parts = parse_url(trim($hot));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[') ? '['.$parts['host'].']' : $parts['host'];

        return $parts['scheme'].'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
