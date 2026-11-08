<?php

declare(strict_types=1);

use App\Models\Lead;
use App\Support\Turnstile;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->seed();
});

function spamLead(array $extra = []): array
{
    return ['name' => 'Bilal', 'email' => 'bilal@example.com', 'message' => 'Hello', ...$extra];
}

it('drops honeypot submissions on both forms but looks successful', function (string $url): void {
    $this->post($url, spamLead(['website' => 'http://spam.example']))
        ->assertRedirect(route('contact').'#contact-form')
        ->assertSessionHas('status', 'lead-sent');

    expect(Lead::query()->count())->toBe(0);
})->with(['/contact', '/prices/question']);

it('rate limits the lead forms together per IP', function (): void {
    $this->post('/contact', spamLead())->assertRedirect();
    $this->post('/prices/question', spamLead())->assertRedirect();
    $this->post('/contact', spamLead())->assertRedirect();
    $this->post('/prices/question', spamLead())->assertTooManyRequests();

    expect(Lead::query()->count())->toBe(3);
});

it('requires and verifies Turnstile when both keys are set', function (): void {
    config(['services.turnstile.site_key' => '1x00000000000000000000AA', 'services.turnstile.secret_key' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
        ->push(['success' => false])
        ->push(['success' => true])]);

    $this->get('/contact')->assertSee('class="cf-turnstile"', false)->assertSee(Turnstile::SCRIPT_URL, false);

    $this->post('/contact', spamLead())->assertSessionHasErrors(Turnstile::RESPONSE_FIELD);
    $this->post('/contact', spamLead([Turnstile::RESPONSE_FIELD => 'bad-token']))->assertSessionHasErrors(Turnstile::RESPONSE_FIELD);
    $this->post('/contact', spamLead([Turnstile::RESPONSE_FIELD => 'good-token']))->assertSessionHasNoErrors();

    expect(Lead::query()->count())->toBe(1);
    Http::assertSentCount(2);
});

it('switches Turnstile off on lead forms when a key is missing, even in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['services.turnstile.site_key' => '1x00000000000000000000AA', 'services.turnstile.secret_key' => null]);
    Http::fake();

    // CSRF is only skipped in the testing environment; it isn't what's tested here.
    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/contact', spamLead())->assertSessionHasNoErrors();

    expect(Lead::query()->count())->toBe(1);
    Http::assertNothingSent();

    // /login keeps failing closed without a secret in production (PLAN §7).
    expect(app(Turnstile::class)->verify('token'))->toBeFalse();
});
