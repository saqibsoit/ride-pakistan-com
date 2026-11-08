<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Discounts\ChangeOrderDiscounts;
use App\Actions\Discounts\CompleteCoveredOrder;
use App\Actions\Payments\ChooseBankTransfer;
use App\Actions\Payments\CreateStripeCheckout;
use App\Enums\AccountCreditReason;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderPaid;
use App\Exceptions\CheckoutUnavailable;
use App\Exceptions\DiscountRejected;
use App\Models\AccountCreditEntry;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Rider;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/** A pending order for one rider at PKR $pricePkr. */
function coOrder(int $pricePkr = 10_000, int $riders = 1): Order
{
    $user = User::factory()->create();
    $product = Product::factory()->create(['price_minor' => $pricePkr * 100]);
    $riderIds = array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys());

    return app(CreateOrder::class)->handle($user, $product, nearSlot(random_int(3, 40), 8, ['capacity' => 6])->id, $riderIds);
}

/** @param  array<string, mixed>  $session */
function coWebhook(TestCase $test, array $session): TestResponse
{
    $payload = (string) json_encode(FakeStripeGateway::event('checkout.session.completed', $session));

    return $test->call('POST', '/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => FakeStripeGateway::sign($payload)], content: $payload);
}

beforeEach(function (): void {
    Notification::fake();
});

it('applies a code on the checkout page and shows the breakdown', function (): void {
    $order = coOrder(10_000);
    $promo = PromoCode::factory()->fixed(150_000)->create(['code' => 'WELCOME15']);

    $this->actingAs($order->user)->post(route('checkout.discount', $order->reference), ['code' => 'welcome15'])
        ->assertRedirect(route('checkout.show', $order->reference))
        ->assertSessionHas('discount_status', 'Promo code applied.');

    $this->actingAs($order->user)->get(route('checkout.show', $order->reference))
        ->assertOk()
        ->assertSee('Subtotal')
        ->assertSee('Promo code WELCOME15')
        ->assertSee('PKR 8,500')
        ->assertSee('To pay');

    $this->actingAs($order->user)->delete(route('checkout.discount.remove', [$order->reference, 'promo']))->assertRedirect();
    expect($order->refresh()->amount_minor)->toBe(1_000_000);

    expect($promo->exists)->toBeTrue();
});

it('shows why a code was refused, and keeps other customers out', function (): void {
    $order = coOrder();

    $this->actingAs($order->user)->post(route('checkout.discount', $order->reference), ['code' => 'NOTACODE'])
        ->assertSessionHasErrors(['code' => 'We don’t recognise that code. Check it and try again.']);

    $this->actingAs(User::factory()->create())->post(route('checkout.discount', $order->reference), ['code' => 'X'])->assertNotFound();
});

it('limits code attempts', function (): void {
    $order = coOrder();

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($order->user)->post(route('checkout.discount', $order->reference), ['code' => 'GUESS'.$i]);
    }

    $this->actingAs($order->user)->post(route('checkout.discount', $order->reference), ['code' => 'GUESS11'])->assertTooManyRequests();
});

it('toggles account credit on the checkout page', function (): void {
    $order = coOrder(10_000);
    AccountCreditEntry::query()->create(['user_id' => $order->user_id, 'delta_minor' => 200_000, 'reason' => AccountCreditReason::Adjusted, 'note' => 'Test']);

    $this->actingAs($order->user)->get(route('checkout.show', $order->reference))->assertSee('Use my credit');
    $this->actingAs($order->user)->post(route('checkout.credit', $order->reference), ['use' => '1'])->assertRedirect();

    expect($order->refresh()->amount_minor)->toBe(800_000);

    $this->actingAs($order->user)->get(route('checkout.show', $order->reference))->assertSee('Don’t use my credit')->assertSee('PKR 2,000');
});

it('charges Stripe exactly the amount left after discounts', function (): void {
    $order = coOrder(10_000, riders: 2);
    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, PromoCode::factory()->create(['percent' => 10, 'code' => 'TEN'])->code);

    app(CreateStripeCheckout::class)->handle($order->refresh());

    $params = $this->stripe()->lastParams();
    $lines = $params['line_items'];

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['quantity'])->toBe(1)
        ->and($lines[0]['price_data']['unit_amount'])->toBe(1_800_000)
        ->and($lines[0]['price_data']['product_data']['name'])->toContain('after discounts')
        ->and($lines[0]['price_data']['product_data']['description'])->toContain('Promo code TEN');
});

it('fulfils when Stripe reports the discounted amount, and flags a mismatch', function (): void {
    $order = coOrder(10_000);
    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, PromoCode::factory()->fixed(200_000)->create()->code);
    app(CreateStripeCheckout::class)->handle($order->refresh());
    $order->refresh();

    $session = [
        'id' => $order->stripe_checkout_session_id, 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
        'client_reference_id' => (string) $order->id, 'metadata' => ['reference' => $order->reference, 'order_id' => (string) $order->id],
        'amount_total' => 800_000, 'currency' => 'pkr', 'payment_intent' => 'pi_test_'.$order->id,
    ];

    // The full price is not what was agreed: never fulfilled.
    $other = coOrder(10_000);
    app(ChangeOrderDiscounts::class)->applyCode($other, $other->user, PromoCode::factory()->fixed(200_000)->create()->code);
    app(CreateStripeCheckout::class)->handle($other->refresh());
    $other->refresh();
    coWebhook($this, [...$session, 'id' => $other->stripe_checkout_session_id, 'client_reference_id' => (string) $other->id, 'metadata' => ['reference' => $other->reference, 'order_id' => (string) $other->id], 'amount_total' => 1_000_000, 'payment_intent' => 'pi_test_other'])->assertOk();
    expect($other->refresh()->status)->toBe(OrderStatus::PendingPayment);

    coWebhook($this, $session)->assertOk();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->bookings()->count())->toBe(1);
});

it('refuses card below the minimum and bank transfer when nothing is left', function (): void {
    config(['services.stripe.min_charge_pkr' => 200]);
    $order = coOrder(10_000);
    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, Voucher::factory()->create(['initial_minor' => 990_000, 'balance_minor' => 990_000])->code);

    expect($order->refresh()->amount_minor)->toBe(10_000)
        ->and(fn () => app(CreateStripeCheckout::class)->handle($order))->toThrow(CheckoutUnavailable::class, 'too small');

    $this->actingAs($order->user)->get(route('checkout.show', $order->reference))->assertSee('below the smallest card payment');

    $covered = coOrder(5_000);
    app(ChangeOrderDiscounts::class)->applyCode($covered, $covered->user, Voucher::factory()->create()->code);
    expect(fn () => app(ChooseBankTransfer::class)->handle($covered->refresh()))->toThrow(CheckoutUnavailable::class, 'confirm');
});

it('confirms a fully covered order without a payment', function (): void {
    Event::fake([OrderPaid::class]);
    $order = coOrder(5_000);
    $voucher = Voucher::factory()->create(['initial_minor' => 1_000_000, 'balance_minor' => 1_000_000]);
    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $voucher->code);

    $this->actingAs($order->user)->get(route('checkout.show', $order->reference))->assertSee('Nothing left to pay.')->assertDontSee('Pay by card');

    $this->actingAs($order->user)->post(route('checkout.confirm', $order->reference))->assertRedirect(route('checkout.status', $order->reference));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->payment_method)->toBe(PaymentMethod::Discounts)
        ->and(Booking::query()->where('order_id', $order->id)->sole()->status)->toBe(BookingStatus::Confirmed)
        ->and($voucher->refresh()->balance_minor)->toBe(500_000)
        ->and($order->payments()->count())->toBe(0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);

    // Pressing again changes nothing.
    app(CompleteCoveredOrder::class)->handle($order, $order->user);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('never confirms an order that still costs something, or someone else’s', function (): void {
    $order = coOrder(10_000);

    expect(fn () => app(CompleteCoveredOrder::class)->handle($order, $order->user))->toThrow(DiscountRejected::class, 'still has an amount')
        ->and(fn () => app(CompleteCoveredOrder::class)->handle($order, User::factory()->create()))->toThrow(DiscountRejected::class);

    // A 100% promo with no discount line would be PKR 0 too; zero-priced orders without discounts are refused.
    $order->forceFill(['amount_minor' => 0])->save();
    expect(fn () => app(CompleteCoveredOrder::class)->handle($order, $order->user))->toThrow(DiscountRejected::class);
});

it('closes an open card page before the amount changes', function (): void {
    $order = coOrder(10_000);
    app(CreateStripeCheckout::class)->handle($order);
    $session = $order->refresh()->stripe_checkout_session_id;

    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, PromoCode::factory()->create()->code);

    expect($this->stripe()->expired)->toContain($session)
        ->and($order->refresh()->stripe_checkout_session_id)->toBeNull();

    app(CreateStripeCheckout::class)->handle($order);
    expect($this->stripe()->lastParams()['line_items'][0]['price_data']['unit_amount'])->toBe(900_000);
});

it('says at the booking review that discounts come at checkout', function (): void {
    $this->get(route('book'))->assertOk();
    expect(file_get_contents(resource_path('views/livewire/book/booking-flow.blade.php')))->toContain('applied at checkout');
});
