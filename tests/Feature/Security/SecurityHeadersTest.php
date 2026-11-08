<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

/** @param TestResponse<Response> $response */
function assertBaseSecurityHeaders(TestResponse $response): void
{
    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->toContain('geolocation=()')
        ->toContain('payment=()');
}

it('sends the security headers on public pages, first view and cached', function (string $path): void {
    $first = $this->get($path)->assertOk();
    $cached = $this->get($path)->assertOk();

    foreach ([$first, $cached] as $response) {
        assertBaseSecurityHeaders($response);
        expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    }
})->with(['/', '/prices', '/schedule', '/faq', '/contact', '/login', '/robots.txt']);

it('sends the security headers on account, checkout-style and admin pages', function (): void {
    $customer = User::factory()->create();

    assertBaseSecurityHeaders($this->actingAs($customer)->get('/account')->assertOk());

    $admin = User::factory()->admin()->withMfa()->create();
    assertBaseSecurityHeaders($this->actingAs($admin)->get('/test-admin')->assertOk());
    assertBaseSecurityHeaders($this->get('/test-admin/login'));
});

it('sends the headers on the Stripe webhook, which has no session', function (): void {
    $response = $this->postJson('/webhooks/stripe', []);

    assertBaseSecurityHeaders($response);
    expect($response->headers->getCookies())->toBe([]);
});

it('sends HSTS with preload over HTTPS only', function (): void {
    $this->get('https://localhost/prices')
        ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');

    $this->get('http://localhost/prices')->assertHeaderMissing('Strict-Transport-Security');
});

it('upgrades insecure requests only on HTTPS', function (): void {
    expect($this->get('https://localhost/')->headers->get('Content-Security-Policy'))->toContain('upgrade-insecure-requests')
        ->and($this->get('http://localhost/')->headers->get('Content-Security-Policy'))->not->toContain('upgrade-insecure-requests');
});

it('never shows a stack trace or exception message with debug off', function (): void {
    config(['app.debug' => false]);

    Route::middleware('web')->get('/__security-boom', function (): never {
        throw new RuntimeException('database password is hunter2');
    });

    $this->get('/__security-boom')
        ->assertStatus(500)
        ->assertDontSee('hunter2')
        ->assertDontSee('RuntimeException')
        ->assertDontSee('Stack trace');
});
