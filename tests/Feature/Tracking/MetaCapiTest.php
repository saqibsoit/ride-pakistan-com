<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Support\Tracking\Consent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->seed();
    config([
        'services.meta.pixel_id' => '123456789012345',
        'services.meta.capi_token' => 'capi-token',
        'services.meta.graph_version' => 'v21.0',
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
});

/** @return array<string, mixed> */
function sentMetaEvent(): array
{
    $request = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])
        ->first(fn (Request $r): bool => str_contains($r->url(), 'graph.facebook.com'));

    expect($request)->not->toBeNull();
    expect($request->url())->toBe('https://graph.facebook.com/v21.0/123456789012345/events');

    return $request->data();
}

it('sends a consented Lead with hashed contact details and the browser event id', function (): void {
    $this->withUnencryptedCookie(Consent::COOKIE, Consent::GRANTED)
        ->withUnencryptedCookie('_fbc', 'fb.1.1700000000000.AbCdEf')
        ->withHeader('User-Agent', 'TestBrowser/1.0')
        ->withHeader('Referer', 'https://ride-pakistan.com/prices')
        ->post('/prices/question', ['name' => 'Iqra', 'email' => ' Iqra@Example.com ', 'phone' => '0300 1234567'])
        ->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();
    $body = sentMetaEvent();
    $event = $body['data'][0];

    expect($body['access_token'])->toBe('capi-token')
        ->and($body)->not->toHaveKey('test_event_code')
        ->and($event)->toMatchArray([
            'event_name' => 'Lead',
            'event_id' => $lead->tracking_event_id,
            'action_source' => 'website',
            'event_source_url' => 'https://ride-pakistan.com/prices',
            'event_time' => $lead->created_at->getTimestamp(),
        ])
        ->and($event['user_data'])->toMatchArray([
            'em' => [hash('sha256', 'iqra@example.com')],
            'ph' => [hash('sha256', '923001234567')],
            'fbc' => 'fb.1.1700000000000.AbCdEf',
            'client_user_agent' => 'TestBrowser/1.0',
        ])
        ->and($event['user_data'])->toHaveKey('client_ip_address')
        ->and($event)->not->toHaveKey('custom_data');

    expect(json_encode($body))->not->toContain('iqra@example.com');
});

it('sends nothing without consent, or without a token', function (): void {
    $this->post('/contact', ['name' => 'No Consent', 'email' => 'nc@example.com'])->assertSessionHasNoErrors();

    config(['services.meta.capi_token' => null]);
    $this->withUnencryptedCookie(Consent::COOKIE, Consent::GRANTED)
        ->post('/contact', ['name' => 'No Token', 'email' => 'nt@example.com'])
        ->assertSessionHasNoErrors();

    $this->withUnencryptedCookie(Consent::COOKIE, Consent::DENIED)
        ->post('/contact', ['name' => 'Denied', 'email' => 'd@example.com']);

    Http::assertNothingSent();
    expect(Lead::query()->count())->toBe(3)
        ->and(Lead::query()->where('consent_tracking', true)->pluck('name')->all())->toBe(['No Token']);
});

it('sends one Purchase per paid order, in PKR, with the order reference', function (): void {
    $customer = User::factory()->create(['email' => 'buyer@example.com', 'phone' => '+923009998877']);
    $order = Order::factory()->status(OrderStatus::Paid)->create([
        'user_id' => $customer->id,
        'amount_minor' => 6_000_000,
        'meta' => ['tracking' => ['checkout_at' => now()->toIso8601String(), 'consent' => true, 'ip' => '203.0.113.9', 'ua' => 'Phone/2', 'fbp' => 'fb.1.1.2', 'url' => 'https://ride-pakistan.com/checkout/X']],
    ]);

    event(new OrderPaid($order));
    event(new OrderPaid($order->fresh() ?? $order));

    Http::assertSentCount(1);
    $event = sentMetaEvent()['data'][0];

    expect($event)->toMatchArray([
        'event_name' => 'Purchase',
        'event_id' => 'purchase-'.$order->reference,
        'custom_data' => ['currency' => 'PKR', 'value' => 60000, 'order_id' => $order->reference],
    ])->and($event['user_data'])->toMatchArray([
        'em' => [hash('sha256', 'buyer@example.com')],
        'ph' => [hash('sha256', '923009998877')],
        'fbp' => 'fb.1.1.2',
        'client_ip_address' => '203.0.113.9',
        'client_user_agent' => 'Phone/2',
    ]);

    expect($order->fresh()?->meta['tracking'])->toHaveKey('capi_purchase_sent_at');
});

it('sends no Purchase for an order placed without consent', function (): void {
    $order = Order::factory()->status(OrderStatus::Paid)->create(['meta' => ['tracking' => ['consent' => false]]]);
    $legacy = Order::factory()->status(OrderStatus::Paid)->create();

    event(new OrderPaid($order));
    event(new OrderPaid($legacy));

    Http::assertNothingSent();
});

it('never breaks the flow when Meta is down', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 400)]);

    $this->withUnencryptedCookie(Consent::COOKIE, Consent::GRANTED)
        ->post('/contact', ['name' => 'Still Saved', 'email' => 'ok@example.com'])
        ->assertRedirect()
        ->assertSessionHas('status', 'lead-sent');

    expect(Lead::query()->count())->toBe(1);
});
