<?php

declare(strict_types=1);

use App\Enums\LeadSource;
use App\Models\Lead;
use App\Support\Tracking\Attribution;
use App\Support\Tracking\Consent;

beforeEach(function (): void {
    $this->seed();
});

function leadPayload(array $extra = []): array
{
    return [
        'name' => 'Hira Malik',
        'phone' => '+92 300 7654321',
        'email' => 'hira@example.com',
        'interest' => 'single',
        'message' => 'Is Saturday morning OK for a beginner?',
        ...$extra,
    ];
}

it('stores a /prices question with its own source and UTM attribution', function (): void {
    $this->post('/prices/question', leadPayload([
        'source' => 'instagram_dm', // ignored: the route decides
        'utm_source' => 'instagram',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'winter-beginners',
        'gclid' => 'g-123',
        'fbclid' => 'fb-456',
        'landing_url' => 'https://ride-pakistan.com/prices?utm_source=instagram',
        'referrer' => 'https://l.instagram.com/',
    ]))->assertRedirect(route('contact').'#contact-form')
        ->assertSessionHas('status', 'lead-sent');

    $lead = Lead::query()->sole();

    expect($lead->source)->toBe(LeadSource::PricesForm)
        ->and($lead->utm_campaign)->toBe('winter-beginners')
        ->and($lead->gclid)->toBe('g-123')
        ->and($lead->fbclid)->toBe('fb-456')
        ->and($lead->landing_url)->toBe('https://ride-pakistan.com/prices?utm_source=instagram')
        ->and($lead->referrer)->toBe('https://l.instagram.com/')
        ->and($lead->first_touch_at)->toBeNull()
        ->and($lead->consent_tracking)->toBeFalse()
        ->and($lead->tracking_event_id)->not->toBeNull();
});

it('prefers the first-touch cookie and records the first visit time', function (): void {
    $firstVisit = now()->subDays(3)->startOfMinute();

    $this->withUnencryptedCookie(Attribution::COOKIE, json_encode([
        'utm_source' => 'facebook',
        'utm_campaign' => 'launch',
        'fbclid' => 'first-click',
        'landing_url' => 'https://ride-pakistan.com/?utm_source=facebook',
        'referrer' => 'https://m.facebook.com/',
        'ts' => $firstVisit->getTimestamp(),
    ]))->withUnencryptedCookie(Consent::COOKIE, Consent::GRANTED)
        ->post('/contact', leadPayload(['utm_source' => 'google', 'landing_url' => 'https://ride-pakistan.com/contact']))
        ->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->utm_source)->toBe('facebook')
        ->and($lead->utm_campaign)->toBe('launch')
        ->and($lead->fbclid)->toBe('first-click')
        ->and($lead->landing_url)->toBe('https://ride-pakistan.com/?utm_source=facebook')
        ->and($lead->first_touch_at?->getTimestamp())->toBe($firstVisit->getTimestamp())
        ->and($lead->consent_tracking)->toBeTrue();
});

it('ignores a malformed, oversized or out-of-range first-touch cookie', function (string $cookie): void {
    $this->withUnencryptedCookie(Attribution::COOKIE, $cookie)
        ->post('/contact', leadPayload(['utm_source' => 'hidden-field']))
        ->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->utm_source)->toBe('hidden-field')
        ->and($lead->first_touch_at)->toBeNull();
})->with([
    'not json' => ['{oops'],
    'not an object' => ['"just a string"'],
    'too big' => [json_encode(['utm_source' => str_repeat('x', 5000)])],
    'empty' => [json_encode(['ts' => 'soon'])],
    'future time only' => [json_encode(['ts' => now()->addYear()->getTimestamp()])],
]);

it('caps and cleans values from the cookie', function (): void {
    $this->withUnencryptedCookie(Attribution::COOKIE, json_encode([
        'utm_source' => str_repeat('a', 300),
        'landing_url' => 'javascript:alert(1)',
        'referrer' => "https://evil.example/\n",
        'ts' => now()->subYears(2)->getTimestamp(),
    ]))->post('/contact', leadPayload())->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();

    expect(mb_strlen((string) $lead->utm_source))->toBe(255)
        ->and($lead->landing_url)->toBeNull()
        ->and($lead->referrer)->toBe('https://evil.example/')
        ->and($lead->first_touch_at)->toBeNull();
});

it('shows /prices validation errors on /contact, which is not cached', function (): void {
    $this->post('/prices/question', ['name' => ''])
        ->assertRedirect(route('contact').'#contact-form')
        ->assertSessionHasErrors(['name', 'phone', 'email']);

    expect(Lead::query()->count())->toBe(0);
});

it('renders the ask-a-question form on /prices with hidden attribution fields', function (): void {
    $this->get('/prices')
        ->assertOk()
        ->assertSee('action="'.route('prices.question').'"', false)
        ->assertSee('data-lead-attribution', false)
        ->assertSee('name="utm_source" value=""', false)
        ->assertSee('id="ask-name"', false);
});
