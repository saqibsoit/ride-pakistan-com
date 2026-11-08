<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\ReleaseExpiredHolds;
use App\Actions\Payments\CreateStripeCheckout;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\CheckoutUnavailable;
use App\Livewire\Checkout\PaymentStatus;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

function heldOrder(int $riders = 2, ?Product $product = null): Order
{
    $user = User::factory()->create();
    $riderIds = array_values(array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys()));
    $product ??= Product::factory()->create(['price_minor' => 1_000_000]);
    $slot = Slot::factory()->at(CarbonImmutable::now()->addDays(5))->create(['capacity' => 6]);

    return app(CreateOrder::class)->handle($user, $product, $slot->id, $riderIds);
}

it('creates a PKR Checkout Session from the order items, never from the request', function (): void {
    $order = heldOrder(3);

    $url = app(CreateStripeCheckout::class)->handle($order);
    $params = $this->stripe()->lastParams();
    $order->refresh();

    expect($url)->toStartWith('https://checkout.stripe.com/')
        ->and($params['mode'])->toBe('payment')
        ->and($params['line_items'])->toHaveCount(1)
        ->and($params['line_items'][0]['quantity'])->toBe(3)
        ->and($params['line_items'][0]['price_data']['currency'])->toBe('pkr')
        ->and($params['line_items'][0]['price_data']['unit_amount'])->toBe(1_000_000)
        ->and($params['client_reference_id'])->toBe((string) $order->id)
        ->and($params['metadata'])->toBe(['order_id' => (string) $order->id, 'reference' => $order->reference])
        ->and($params['customer_email'])->toBe($order->user->email)
        ->and($params['success_url'])->toBe(route('checkout.status', $order->reference).'?session_id={CHECKOUT_SESSION_ID}')
        ->and($params['cancel_url'])->toContain('cancelled=1')
        ->and($params['payment_intent_data']['statement_descriptor_suffix'])->toBe('RIDE PAKISTAN')
        ->and($params['custom_text']['submit']['message'])->toContain('Ride Pakistan by Destination Pakistan')
        ->and($this->stripe()->created[0]['key'])->toBe('checkout:'.$order->id.':1')
        ->and($order->stripe_checkout_session_id)->toBe('cs_test_1')
        ->and($order->payment_method)->toBe(PaymentMethod::Card);
});

it('charges a package once, leaving its credit-paid first session out', function (): void {
    $order = heldOrder(2, Product::factory()->package(8, 6_000_000)->create());

    app(CreateStripeCheckout::class)->handle($order);
    $lines = $this->stripe()->lastParams()['line_items'];

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['quantity'])->toBe(1)
        ->and($lines[0]['price_data']['unit_amount'])->toBe(6_000_000);
});

it('extends the seat hold so it lasts as long as the Stripe session', function (): void {
    $order = heldOrder();
    $this->travel(20)->minutes();

    app(CreateStripeCheckout::class)->handle($order);
    $order->refresh();
    $expiresAt = $this->stripe()->lastParams()['expires_at'];

    expect($expiresAt)->toBe($order->expires_at?->getTimestamp())
        ->and($expiresAt - now()->getTimestamp())->toBeGreaterThanOrEqual(CreateStripeCheckout::MIN_SESSION_MINUTES * 60)
        ->and(BookingHold::query()->where('order_id', $order->id)->sole()->expires_at->getTimestamp())->toBe($expiresAt);
});

it('reuses an open session and starts a new attempt after one expired', function (): void {
    $order = heldOrder();
    $action = app(CreateStripeCheckout::class);

    $first = $action->handle($order);
    expect($action->handle($order))->toBe($first)
        ->and($this->stripe()->created)->toHaveCount(1);

    $this->stripe()->sessions['cs_test_1']['status'] = 'expired';

    $second = $action->handle($order);

    expect($second)->not->toBe($first)
        ->and($this->stripe()->created)->toHaveCount(2)
        ->and($this->stripe()->created[1]['key'])->toBe('checkout:'.$order->id.':2');
});

it('refuses orders that are paid, expired or past their hold', function (): void {
    $action = app(CreateStripeCheckout::class);

    $paid = Order::factory()->status(OrderStatus::Paid)->create();
    expect(fn () => $action->handle($paid))->toThrow(CheckoutUnavailable::class, 'received your payment');

    $order = heldOrder();
    $this->travel(31)->minutes();
    expect(fn () => $action->handle($order))->toThrow(CheckoutUnavailable::class, 'can no longer be paid');

    app(ReleaseExpiredHolds::class)->handle();
    expect(fn () => $action->handle($order->refresh()))->toThrow(CheckoutUnavailable::class)
        ->and($this->stripe()->created)->toBeEmpty();
});

it('says card payment is unavailable when Stripe fails', function (): void {
    $this->stripe()->failCreate = true;

    expect(fn () => app(CreateStripeCheckout::class)->handle(heldOrder()))
        ->toThrow(CheckoutUnavailable::class, 'bank transfer');
});

it('redirects the customer to Stripe from the checkout page', function (): void {
    $order = heldOrder();

    $this->actingAs($order->user)
        ->post(route('checkout.card', $order->reference))
        ->assertStatus(303)
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
});

it('shows both payment options and the Pakistani card note', function (): void {
    $order = heldOrder();

    $this->actingAs($order->user)->get(route('checkout.show', $order->reference))
        ->assertOk()
        ->assertSee('Pay by card')
        ->assertSee('Pay by bank transfer')
        ->assertSee('Some Pakistani bank cards block international online payments')
        ->assertSee('Ride Pakistan by Destination Pakistan');
});

it('shows the Stripe error on the checkout page', function (): void {
    $order = heldOrder();
    $this->stripe()->failCreate = true;

    $this->actingAs($order->user)
        ->followingRedirects()
        ->post(route('checkout.card', $order->reference))
        ->assertOk()
        ->assertSee('Card payment is not available right now');
});

it("never lets a customer pay or see another customer's order", function (): void {
    $order = heldOrder();
    $other = User::factory()->create();

    $this->actingAs($other)->post(route('checkout.card', $order->reference))->assertNotFound();
    $this->actingAs($other)->get(route('checkout.status', $order->reference))->assertNotFound();
    Auth::logout();
    $this->post(route('checkout.card', $order->reference))->assertRedirect(route('login'));

    expect($this->stripe()->created)->toBeEmpty();
});

it('rate limits starting card payment', function (): void {
    $order = heldOrder();
    $this->actingAs($order->user);

    foreach (range(1, 10) as $ignored) {
        $this->post(route('checkout.card', $order->reference));
    }

    $this->post(route('checkout.card', $order->reference))->assertTooManyRequests();
});

it('polls the status page until the webhook marks the order paid, without fulfilling', function (): void {
    $order = heldOrder();
    app(CreateStripeCheckout::class)->handle($order);
    $this->actingAs($order->user);

    $this->get(route('checkout.status', $order->reference).'?session_id=cs_test_1')
        ->assertOk()
        ->assertSee('Confirming your payment');

    $component = Livewire::test(PaymentStatus::class, ['reference' => $order->reference])
        ->assertSee('Confirming your payment')
        ->assertSeeHtml('wire:poll.3s="poll"')
        ->call('poll');

    // Polling never changes the order.
    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    $order->setRawAttributes([...$order->getAttributes(), 'status' => OrderStatus::Paid->value]);
    $order->save();

    $component->call('poll')
        ->assertSee("You're booked")
        ->assertDontSeeHtml('wire:poll');
});

it('stops polling after two minutes', function (): void {
    $order = heldOrder();
    app(CreateStripeCheckout::class)->handle($order);
    $this->actingAs($order->user);

    $component = Livewire::test(PaymentStatus::class, ['reference' => $order->reference]);

    foreach (range(1, PaymentStatus::MAX_POLLS) as $ignored) {
        $component->call('poll');
    }

    $component->assertSee('still confirming your payment')
        ->assertDontSeeHtml('wire:poll');
});

it('offers card again or bank transfer after a failed attempt', function (): void {
    $order = heldOrder();
    app(CreateStripeCheckout::class)->handle($order);
    $order->refresh();
    $order->stripe_checkout_session_id = null; // as the expired / failed webhook does
    $order->save();

    $this->actingAs($order->user);

    Livewire::test(PaymentStatus::class, ['reference' => $order->reference])
        ->assertSee("didn't go through")
        ->assertSee($order->reference)
        ->assertDontSeeHtml('wire:poll');
});

it('tells a customer who cancelled on Stripe that nothing was charged', function (): void {
    $order = heldOrder();

    $this->actingAs($order->user)
        ->get(route('checkout.status', $order->reference).'?cancelled=1')
        ->assertOk()
        ->assertSee('Nothing was charged');
});

it('expires the open Stripe session before expiring an order whose hold ran out', function (): void {
    $order = heldOrder();
    app(CreateStripeCheckout::class)->handle($order);
    $order->refresh();

    $this->travelTo($order->expires_at?->addMinute());
    app(ReleaseExpiredHolds::class)->handle();

    expect($this->stripe()->expired)->toBe(['cs_test_1'])
        ->and($order->refresh()->status)->toBe(OrderStatus::Expired);
});

it('leaves an order whose session Stripe already completed for the webhook', function (): void {
    $order = heldOrder();
    app(CreateStripeCheckout::class)->handle($order);
    $order->refresh();
    $this->stripe()->complete('cs_test_1');

    $this->travelTo($order->expires_at?->addMinute());
    app(ReleaseExpiredHolds::class)->handle();

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('expires the order anyway once a day has passed even if Stripe is unreachable', function (): void {
    $order = heldOrder();
    app(CreateStripeCheckout::class)->handle($order);
    $order->refresh();
    $this->stripe()->failRetrieve = true;

    $this->travelTo($order->expires_at?->addMinute());
    app(ReleaseExpiredHolds::class)->handle();
    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    $this->travelTo($order->expires_at?->addDay()->addMinute());
    app(ReleaseExpiredHolds::class)->handle();
    expect($order->refresh()->status)->toBe(OrderStatus::Expired);
});

it('stripe:check refuses live keys', function (): void {
    $this->stripe()->testMode = false;

    $this->artisan('stripe:check')->expectsOutputToContain('must be a test key')->assertFailed();
    expect($this->stripe()->created)->toBeEmpty();
});

it('stripe:check creates and expires a PKR session', function (): void {
    $this->artisan('stripe:check')->expectsOutputToContain('OK: this account can create PKR Checkout Sessions')->assertSuccessful();

    expect($this->stripe()->lastParams()['line_items'][0]['price_data']['currency'])->toBe('pkr')
        ->and($this->stripe()->expired)->toBe(['cs_test_1']);
});
