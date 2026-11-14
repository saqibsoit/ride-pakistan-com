<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tracking\Consent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

function headerNonce(string $policy): string
{
    preg_match("/'nonce-([^']+)'/", $policy, $match);

    return $match[1] ?? '';
}

/** @return list<string> every <script ...> opening tag */
function scriptOpeningTags(string $html): array
{
    preg_match_all('/<script\b[^>]*>/i', $html, $matches);

    return $matches[0];
}

it('puts the request nonce on every script, also on cached pages', function (string $path): void {
    $first = $this->get($path)->assertOk();
    $cached = $this->get($path)->assertOk();

    $nonces = [];

    foreach ([$first, $cached] as $response) {
        $nonce = headerNonce((string) $response->headers->get('Content-Security-Policy'));
        $tags = scriptOpeningTags((string) $response->getContent());

        expect($nonce)->not->toBe('')
            ->and($tags)->not->toBeEmpty();

        foreach ($tags as $tag) {
            expect($tag)->toContain('nonce="'.$nonce.'"');
        }

        $nonces[] = $nonce;
    }

    // A cache hit gets its own nonce, never the one that filled the cache.
    expect($nonces[0])->not->toBe($nonces[1]);
})->with(['/', '/prices', '/schedule', '/sessions/single-session', '/book', '/login']);

it('allows scripts only by nonce on public pages: no unsafe-inline or unsafe-eval', function (): void {
    $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("script-src 'nonce-")
        ->toContain("'strict-dynamic'")
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'none'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain("'unsafe-eval'")
        ->not->toContain("'unsafe-inline'");
});

it('allows Turnstile and the Stripe Checkout redirect', function (): void {
    $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');

    expect($policy)->toContain('frame-src https://challenges.cloudflare.com')
        ->toContain('https://challenges.cloudflare.com')
        ->toContain("form-action 'self' https://checkout.stripe.com");
});

it('lists Meta and GA4 only for visitors who consented, and only when configured', function (): void {
    config(['services.meta.pixel_id' => '1234567890', 'services.ga4.measurement_id' => 'G-TEST1234']);

    $without = (string) $this->get('/prices')->headers->get('Content-Security-Policy');
    $declined = (string) $this->withUnencryptedCookie(Consent::COOKIE, Consent::DENIED)->get('/prices')->headers->get('Content-Security-Policy');
    $granted = (string) $this->withUnencryptedCookie(Consent::COOKIE, Consent::GRANTED)->get('/prices')->headers->get('Content-Security-Policy');

    foreach ([$without, $declined] as $policy) {
        expect($policy)->not->toContain('facebook')->not->toContain('googletagmanager')->not->toContain('google-analytics');
    }

    expect($granted)->toContain('https://connect.facebook.net')
        ->toContain('https://www.facebook.com')
        ->toContain('https://www.googletagmanager.com')
        ->toContain('https://*.google-analytics.com');
});

it('lists only the configured vendor after consent', function (): void {
    config(['services.meta.pixel_id' => '', 'services.ga4.measurement_id' => 'G-TEST1234']);

    $policy = (string) $this->withUnencryptedCookie(Consent::COOKIE, Consent::GRANTED)->get('/')->headers->get('Content-Security-Policy');

    expect($policy)->toContain('googletagmanager')->not->toContain('facebook');
});

it('gives the admin panel unsafe-eval and inline styles, nothing else extra', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();

    $policy = (string) $this->actingAs($admin)->get('/test-admin')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("'unsafe-eval'")
        ->toContain("style-src 'self' 'unsafe-inline'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('bunny')
        ->not->toContain('ui-avatars');
});

it('loads no third-party fonts or avatars in the admin panel', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();

    $this->actingAs($admin)->get('/test-admin')
        ->assertOk()
        ->assertDontSee('fonts.bunny.net')
        ->assertDontSee('ui-avatars.com');
});

it('loads Livewire’s script in the admin panel with the nonce (asset injection is off)', function (): void {
    $response = $this->get('/test-admin/login')->assertOk();
    $nonce = headerNonce((string) $response->headers->get('Content-Security-Policy'));

    expect((string) $response->getContent())->toMatch('#<script src="[^"]*/livewire[^"]*\.js[^"]*"\s+nonce="'.preg_quote($nonce, '#').'"#');
});

it('can run in report-only mode for a staging trial', function (): void {
    config(['csp.report_only' => true]);

    $response = $this->get('/');

    $response->assertHeaderMissing('Content-Security-Policy');
    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("'strict-dynamic'");
});

it('points reports at the report endpoint', function (): void {
    $response = $this->get('/');

    expect($response->headers->get('Content-Security-Policy'))->toContain('report-uri '.route('csp.report'))
        ->toContain('report-to csp')
        ->and($response->headers->get('Reporting-Endpoints'))->toBe('csp="'.route('csp.report').'"');
});

it('logs a legacy CSP report without query strings', function (): void {
    Log::shouldReceive('channel')->with('csp')->once()->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'CSP violation'
            && $context['effective-directive'] === 'script-src-elem'
            && $context['blocked-uri'] === 'https://evil.example/x.js'
            && $context['document-uri'] === 'https://ride-pakistan.com/prices'
            && ! array_key_exists('original-policy', $context);
    });

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
        'document-uri' => 'https://ride-pakistan.com/prices?email=someone@example.com',
        'effective-directive' => 'script-src-elem',
        'blocked-uri' => 'https://evil.example/x.js?token=abc',
        'original-policy' => "default-src 'self'",
    ]]))->assertNoContent();
});

it('logs Reporting API reports', function (): void {
    Log::shouldReceive('channel')->with('csp')->twice()->andReturnSelf();
    Log::shouldReceive('warning')->twice();

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([
        ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'https://a.example/p.gif', 'documentURL' => 'https://ride-pakistan.com/']],
        ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'connect-src', 'blockedURL' => 'https://b.example/', 'documentURL' => 'https://ride-pakistan.com/']],
        ['type' => 'deprecation', 'body' => []],
    ]))->assertNoContent();
});

it('rejects oversized reports and ignores junk', function (): void {
    Log::shouldReceive('channel')->never();

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], str_repeat('a', 17 * 1024))
        ->assertStatus(413);

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], 'not json')
        ->assertNoContent();
});

it('takes reports without a session or CSRF token, and rate limits them', function (): void {
    RateLimiter::clear('csp-report:127.0.0.1');

    $response = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{}');

    $response->assertNoContent();
    expect($response->headers->getCookies())->toBe([]);

    for ($i = 1; $i < 30; $i++) {
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{}');
    }

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{}')
        ->assertTooManyRequests();
});
