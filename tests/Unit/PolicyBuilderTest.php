<?php

declare(strict_types=1);

use App\Support\Csp\PolicyBuilder;

beforeEach(function (): void {
    config(['services.meta.pixel_id' => '1234567890', 'services.ga4.measurement_id' => 'G-TEST1234']);
});

/** @return array<string, string> directive => sources */
function cspDirectives(string $policy): array
{
    $out = [];

    foreach (explode('; ', $policy) as $part) {
        [$name, $sources] = array_pad(explode(' ', $part, 2), 2, '');
        $out[$name] = $sources;
    }

    return $out;
}

it('builds a strict public policy', function (): void {
    $d = cspDirectives(app(PolicyBuilder::class)->build('abc123'));

    expect($d['script-src'])->toBe("'nonce-abc123' 'strict-dynamic' https://challenges.cloudflare.com")
        ->and($d['style-src'])->toBe("'self' 'nonce-abc123'")
        ->and($d['frame-src'])->toBe('https://challenges.cloudflare.com https://www.google.com')
        ->and($d['form-action'])->toBe("'self' https://checkout.stripe.com")
        ->and($d['object-src'])->toBe("'none'")
        ->and($d)->not->toHaveKey('upgrade-insecure-requests')
        ->and($d)->not->toHaveKey('report-uri');
});

it('drops the style nonce when the admin allows inline styles', function (): void {
    $d = cspDirectives(app(PolicyBuilder::class)->build('abc123', admin: true));

    expect($d['script-src'])->toContain("'unsafe-eval'")
        ->and($d['style-src'])->toBe("'self' 'unsafe-inline'");
});

it('adds consent-gated vendors only with consent', function (): void {
    $builder = app(PolicyBuilder::class);

    expect($builder->build('n'))->not->toContain('facebook')
        ->and($builder->build('n', trackingConsent: true))->toContain('https://connect.facebook.net')->toContain('https://www.googletagmanager.com');
});

it('adds reporting, upgrade and the Vite dev server when asked', function (): void {
    $policy = app(PolicyBuilder::class)->build('n', reportUri: 'https://x.test/csp-report', hotUrl: 'http://[::1]:5173', secure: true);
    $d = cspDirectives($policy);

    expect($d['report-uri'])->toBe('https://x.test/csp-report')
        ->and($d['report-to'])->toBe('csp')
        ->and($d)->toHaveKey('upgrade-insecure-requests')
        ->and($d['connect-src'])->toContain('ws://[::1]:5173');
});
