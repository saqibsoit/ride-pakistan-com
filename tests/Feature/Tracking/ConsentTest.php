<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Livewire\Checkout\PaymentStatus;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Support\Csp\NonceReplacer;
use App\Support\Tracking\BrowserEvents;
use App\Support\Tracking\Consent;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed();
});

function enableTracking(): void
{
    config([
        'services.meta.pixel_id' => '123456789012345',
        'services.ga4.measurement_id' => 'G-TEST12345',
    ]);
}

/** Every <script> the server rendered, as raw tags. */
function scriptTags(string $html): array
{
    preg_match_all('#<script\b[^>]*>.*?</script>#is', $html, $matches);

    return $matches[0];
}

it('renders no tracking code, config or banner when no key is set', function (): void {
    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('rp-tracking')
        ->not->toContain('consentBanner')
        ->not->toContain('Cookie settings')
        ->not->toContain('fbevents.js')
        ->not->toContain('googletagmanager.com')
        ->not->toContain('fbq(')
        ->not->toContain('gtag(');
});

it('renders the banner and inert config, but never a vendor script, without consent', function (string $url): void {
    enableTracking();

    $html = (string) $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('x-data="consentBanner"')
        ->toContain('Cookie settings')
        ->toContain('id="rp-tracking"');

    foreach (scriptTags($html) as $tag) {
        expect($tag)
            ->not->toContain('connect.facebook.net')
            ->not->toContain('googletagmanager.com/gtag')
            ->not->toContain('fbq(')
            ->not->toContain('gtag(');
    }

    // The config is data, not code.
    preg_match('#<script type="application/json" id="rp-tracking"[^>]*>(.*?)</script>#s', $html, $config);
    expect(json_decode($config[1] ?? '', true))->toMatchArray(['metaPixelId' => '123456789012345', 'ga4Id' => 'G-TEST12345']);
})->with(['/', '/prices', '/contact']);

it('ignores malformed tracking ids', function (): void {
    config(['services.meta.pixel_id' => '<script>', 'services.ga4.measurement_id' => 'UA-123']);

    expect((string) $this->get('/')->getContent())->not->toContain('rp-tracking');
});

it('queues the Lead event with the lead\'s event id after a form submit', function (): void {
    enableTracking();

    $this->post('/contact', ['name' => 'Rabia', 'email' => 'rabia@example.com'])->assertRedirect();

    $lead = Lead::query()->sole();
    $html = (string) $this->get('/contact')->getContent();

    preg_match('#id="rp-tracking"[^>]*>(.*?)</script>#s', $html, $config);
    $events = json_decode($config[1] ?? '{}', true)['events'] ?? [];

    expect($events)->toBe([['name' => BrowserEvents::LEAD, 'id' => $lead->tracking_event_id]]);
});

it('queues InitiateCheckout once and keeps CAPI context only with consent', function (): void {
    enableTracking();
    $customer = User::factory()->create();
    $order = Order::factory()->status(OrderStatus::PendingPayment)->create(['user_id' => $customer->id, 'amount_minor' => 1_000_000, 'expires_at' => now()->addMinutes(20)]);

    $html = (string) $this->actingAs($customer)
        ->withUnencryptedCookie(Consent::COOKIE, Consent::GRANTED)
        ->withUnencryptedCookie('_fbp', 'fb.1.1700000000000.123456789')
        ->get('/checkout/'.$order->reference)
        ->assertOk()
        ->getContent();

    expect($html)->toContain('"name":"InitiateCheckout","id":"checkout-'.$order->reference.'","value":10000,"currency":"PKR"');

    $tracking = $order->fresh()?->meta['tracking'] ?? [];
    expect($tracking)->toMatchArray(['consent' => true, 'fbp' => 'fb.1.1700000000000.123456789']);

    // Second view: no second InitiateCheckout.
    $again = (string) $this->actingAs($customer)->get('/checkout/'.$order->reference)->getContent();
    expect($again)->not->toContain('InitiateCheckout');
});

it('stores nothing about the visitor at checkout without consent', function (): void {
    enableTracking();
    $customer = User::factory()->create();
    $order = Order::factory()->status(OrderStatus::PendingPayment)->create(['user_id' => $customer->id, 'expires_at' => now()->addMinutes(20)]);

    $this->actingAs($customer)->withUnencryptedCookie('_fbp', 'fb.1.1700000000000.123456789')->get('/checkout/'.$order->reference)->assertOk();

    expect($order->fresh()?->meta['tracking'] ?? null)->toHaveKeys(['checkout_at', 'consent'])
        ->not->toHaveKey('fbp')
        ->not->toHaveKey('ip')
        ->and($order->fresh()?->meta['tracking']['consent'] ?? null)->toBeFalse();
});

it('puts a nonce on every script and gives cached pages a fresh one', function (): void {
    enableTracking();
    config(['responsecache.enabled' => true]);

    $first = (string) $this->get('/prices')->getContent();
    $second = (string) $this->get('/prices')->getContent();

    foreach ([$first, $second] as $html) {
        // JSON-LD is data the browser never runs, so CSP doesn't cover it.
        foreach (array_filter(scriptTags($html), fn (string $tag): bool => ! str_contains($tag, 'application/ld+json')) as $tag) {
            expect($tag)->toMatch('/<script[^>]*\snonce="[A-Za-z0-9]{20,}"/');
        }
        expect($html)->not->toContain(NonceReplacer::PLACEHOLDER);
    }

    preg_match('/nonce="([^"]+)"/', $first, $a);
    preg_match('/nonce="([^"]+)"/', $second, $b);

    expect($a[1])->not->toBe($b[1]);
});

it('fires the browser Purchase once when the status page sees the order paid', function (): void {
    $customer = User::factory()->create();
    $order = Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $customer->id, 'amount_minor' => 6_000_000]);
    $this->actingAs($customer);

    Livewire::test(PaymentStatus::class, ['reference' => $order->reference])
        ->assertNotDispatched('rp-track');

    enableTracking();

    Livewire::test(PaymentStatus::class, ['reference' => $order->reference])
        ->assertDispatched('rp-track', tracking: BrowserEvents::forOrder(BrowserEvents::PURCHASE, $order))
        ->call('poll')
        ->assertNotDispatched('rp-track');
});
