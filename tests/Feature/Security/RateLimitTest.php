<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

it('throttles every write route (PLAN §7)', function (): void {
    $unthrottled = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        // Filament and Livewire endpoints have their own limits and CSRF.
        ->reject(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'filament.')
            || str_starts_with($route->uri(), 'livewire'))
        ->reject(fn (RoutingRoute $route): bool => collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:')))
        ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unthrottled)->toBe([]);
});

it('limits the lead forms', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->post('/contact', []);
    }

    $this->post('/contact', [])->assertTooManyRequests();
    $this->post('/prices/question', [])->assertTooManyRequests();
});

it('limits sign-in code requests per email', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->post('/login', ['email' => 'someone@example.com']);
    }

    $this->post('/login', ['email' => 'someone@example.com'])
        ->assertSessionHasErrors(['email' => 'Too many code requests. Please wait a few minutes and try again.']);
});

it('limits code submissions per IP', function (): void {
    $limit = (int) config('login.verify_limits.per_ip_per_minute');

    for ($i = 0; $i < $limit; $i++) {
        $this->post('/login/code', ['code' => '000000']);
    }

    $this->post('/login/code', ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'Too many attempts. Please wait a minute and try again.']);
});

it('limits starting a payment and uploading proofs', function (): void {
    $this->actingAs(User::factory()->create());

    for ($i = 0; $i < 10; $i++) {
        $this->post('/checkout/RP-AAAAAA/card');
    }

    $this->post('/checkout/RP-AAAAAA/card')->assertTooManyRequests();

    for ($i = 0; $i < 10; $i++) {
        $this->post('/checkout/RP-AAAAAA/transfer/proof');
    }

    $this->post('/checkout/RP-AAAAAA/transfer/proof')->assertTooManyRequests();
});

it('limits account changes', function (): void {
    $this->actingAs(User::factory()->create());

    for ($i = 0; $i < 20; $i++) {
        $this->put('/account/profile', []);
    }

    $this->put('/account/profile', [])->assertTooManyRequests();
    $this->post('/account/sign-out-everywhere')->assertTooManyRequests();
});

it('limits the Stripe webhook', function (): void {
    for ($i = 0; $i < 300; $i++) {
        $this->postJson('/webhooks/stripe', []);
    }

    $this->postJson('/webhooks/stripe', [])->assertTooManyRequests();
});
