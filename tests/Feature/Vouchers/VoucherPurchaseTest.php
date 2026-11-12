<?php

declare(strict_types=1);

use App\Actions\Vouchers\CreateVoucherCheckout;
use App\Actions\Vouchers\CreateVoucherOrder;
use App\Actions\Vouchers\IssueVoucher;
use App\Actions\Vouchers\ReleaseExpiredVoucherOrders;
use App\Actions\Vouchers\VoucherOrderStateMachine;
use App\Enums\SettingKey;
use App\Enums\VoucherLedgerReason;
use App\Enums\VoucherOrderStatus;
use App\Enums\VoucherStatus;
use App\Events\OrderPaid;
use App\Exceptions\VoucherRejected;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StripeEvent;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

function gvOrder(?User $user = null, int $amountMinor = 500_000, ?CarbonImmutable $sendOn = null): VoucherOrder
{
    return app(CreateVoucherOrder::class)->handle(
        $user ?? User::factory()->create(),
        null,
        $amountMinor,
        'Sara Khan',
        'Sara@Example.com',
        'Happy birthday',
        $sendOn,
    );
}

/** A voucher purchase with card payment started (session open). */
function gvCardOrder(int $amountMinor = 500_000): VoucherOrder
{
    $order = gvOrder(amountMinor: $amountMinor);
    app(CreateVoucherCheckout::class)->handle($order);

    return $order->refresh();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gvPaidSession(VoucherOrder $order, array $overrides = []): array
{
    return [
        'id' => $order->stripe_checkout_session_id ?? 'cs_test_v',
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'client_reference_id' => 'voucher:'.$order->id,
        'metadata' => ['kind' => 'voucher', 'reference' => $order->reference, 'voucher_order_id' => (string) $order->id],
        'amount_total' => $order->amount_minor,
        'currency' => 'pkr',
        'payment_intent' => 'pi_test_gv_'.$order->id,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $event
 */
function gvWebhook(TestCase $test, array $event): TestResponse
{
    $payload = (string) json_encode($event);

    return $test->call('POST', '/webhooks/stripe', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => FakeStripeGateway::sign($payload),
    ], content: $payload);
}

beforeEach(function (): void {
    Notification::fake();
});

describe('creating a purchase', function (): void {
    it('takes a product voucher at the product price, never from the request', function (): void {
        $product = Product::factory()->create(['price_minor' => 6_000_000, 'name' => '8-class package']);

        $order = app(CreateVoucherOrder::class)->handle(User::factory()->create(), $product->id, 1, 'Ali', 'ali@example.com', null, null);

        expect($order->amount_minor)->toBe(6_000_000)
            ->and($order->product_name)->toBe('8-class package')
            ->and($order->label())->toBe('Gift voucher: 8-class package')
            ->and($order->status)->toBe(VoucherOrderStatus::PendingPayment)
            ->and(Voucher::query()->count())->toBe(0);
    });

    it('refuses archived or inactive products', function (): void {
        $product = Product::factory()->create(['is_active' => false]);

        app(CreateVoucherOrder::class)->handle(User::factory()->create(), $product->id, null, 'Ali', 'ali@example.com', null, null);
    })->throws(VoucherRejected::class);

    it('keeps amounts inside the settings range, in whole rupees', function (int $amount, bool $ok): void {
        $create = fn () => gvOrder(amountMinor: $amount);

        $ok ? expect($create()->amount_minor)->toBe($amount) : expect($create)->toThrow(VoucherRejected::class);
    })->with([
        'minimum' => [100_000, true],
        'below minimum' => [99_900, false],
        'maximum' => [50_000_000, true],
        'above maximum' => [50_000_100, false],
        'paisa' => [150_050, false],
    ]);

    it('follows the amount settings', function (): void {
        app(Settings::class)->flush();
        Setting::query()->updateOrCreate(['key' => SettingKey::VoucherMinAmountPkr->value], ['value' => 5000]);
        app(Settings::class)->flush();

        expect(fn () => gvOrder(amountMinor: 400_000))->toThrow(VoucherRejected::class);
    });

    it('accepts a send date up to 90 days ahead and treats today as now', function (): void {
        $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();

        expect(gvOrder(sendOn: $today)->send_on)->toBeNull()
            ->and(gvOrder(sendOn: $today->addDays(90))->send_on?->format('Y-m-d'))->toBe($today->addDays(90)->format('Y-m-d'))
            ->and(fn () => gvOrder(sendOn: $today->addDays(91)))->toThrow(VoucherRejected::class)
            ->and(fn () => gvOrder(sendOn: $today->subDay()))->toThrow(VoucherRejected::class);
    });

    it('creates a purchase from the form and validates it', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('gift-vouchers.store'), [
            'kind' => 'amount',
            'amount_pkr' => 5000,
            'recipient_name' => 'Sara',
            'recipient_email' => 'sara@example.com',
            'message' => 'Enjoy',
            'send' => 'now',
        ])->assertRedirect();

        $order = VoucherOrder::query()->sole();
        expect($order->amount_minor)->toBe(500_000)->and($order->user_id)->toBe($user->id);

        $this->actingAs($user)->post(route('gift-vouchers.store'), [
            'kind' => 'amount',
            'amount_pkr' => 5000,
            'recipient_name' => '',
            'recipient_email' => 'not-an-email',
            'send' => 'later',
        ])->assertSessionHasErrors(['recipient_name', 'recipient_email', 'send_on']);
    });

    it('lets guests buy with their own name and email (guest checkout)', function (): void {
        $this->get(route('gift-vouchers.create'))->assertOk()->assertSee('About you')->assertSee('Your email again');
        $this->get(route('gift-vouchers'))->assertOk()->assertSee('Gift vouchers.');
    });
});

describe('card payment', function (): void {
    it('creates a Stripe session for exactly the purchase amount, marked as a voucher', function (): void {
        $order = gvCardOrder(750_000);
        $params = $this->stripe()->lastParams();

        expect($params['line_items'][0]['price_data']['unit_amount'])->toBe(750_000)
            ->and($params['line_items'][0]['price_data']['currency'])->toBe('pkr')
            ->and($params['line_items'][0]['quantity'])->toBe(1)
            ->and($params['metadata'])->toMatchArray(['kind' => 'voucher', 'reference' => $order->reference])
            ->and($params['client_reference_id'])->toBe('voucher:'.$order->id)
            ->and($order->stripe_checkout_session_id)->not->toBeNull();
    });

    it('reuses an open session', function (): void {
        $order = gvCardOrder();

        app(CreateVoucherCheckout::class)->handle($order);

        expect($this->stripe()->created)->toHaveCount(1);
    });

    it('issues exactly one voucher from the webhook, never through order fulfilment', function (): void {
        Event::fake([OrderPaid::class]);
        $order = gvCardOrder();
        $event = FakeStripeGateway::event('checkout.session.completed', gvPaidSession($order));

        gvWebhook($this, $event)->assertOk()->assertJson(['duplicate' => false]);
        gvWebhook($this, $event)->assertOk()->assertJson(['duplicate' => true]);
        // A second, different event for the same payment changes nothing either.
        gvWebhook($this, FakeStripeGateway::event('checkout.session.async_payment_succeeded', gvPaidSession($order)))->assertOk();

        $order->refresh();
        $voucher = Voucher::query()->sole();

        expect($order->status)->toBe(VoucherOrderStatus::Paid)
            ->and($order->stripe_payment_intent_id)->toBe('pi_test_gv_'.$order->id)
            ->and($voucher->voucher_order_id)->toBe($order->id)
            ->and($voucher->initial_minor)->toBe(500_000)
            ->and($voucher->balance_minor)->toBe(500_000)
            ->and($voucher->status)->toBe(VoucherStatus::Active)
            ->and($voucher->recipient_email)->toBe('sara@example.com')
            ->and($voucher->ledger()->sole()->reason)->toBe(VoucherLedgerReason::Issued)
            ->and(Order::query()->count())->toBe(0);

        Event::assertNotDispatched(OrderPaid::class);
    });

    it('gives the voucher 12 months of validity from payment', function (): void {
        CarbonImmutable::setTestNow('2026-11-20 10:00:00');
        $order = gvCardOrder();

        gvWebhook($this, FakeStripeGateway::event('checkout.session.completed', gvPaidSession($order)))->assertOk();

        expect(Voucher::query()->sole()->expires_at->toDateTimeString())->toBe('2027-11-20 10:00:00');
        CarbonImmutable::setTestNow();
    });

    it('never issues a voucher when the amount or currency differs', function (array $overrides): void {
        $order = gvCardOrder();

        gvWebhook($this, FakeStripeGateway::event('checkout.session.completed', gvPaidSession($order, $overrides)))->assertOk();

        $order->refresh();
        expect(Voucher::query()->exists())->toBeFalse()
            ->and($order->status)->toBe(VoucherOrderStatus::PendingPayment)
            ->and($order->needs_review)->toBeTrue()
            ->and(Activity::query()->where('event', 'payment_mismatch')->exists())->toBeTrue();
    })->with([
        'amount' => [['amount_total' => 499_999]],
        'currency' => [['currency' => 'usd']],
    ]);

    it('never issues a voucher when the reference does not match', function (): void {
        $order = gvCardOrder();
        $other = gvOrder();

        gvWebhook($this, FakeStripeGateway::event('checkout.session.completed', gvPaidSession($order, [
            'metadata' => ['kind' => 'voucher', 'reference' => $other->reference],
        ])))->assertOk();

        expect(Voucher::query()->exists())->toBeFalse()
            ->and($order->refresh()->needs_review)->toBeTrue();
    });

    it('still issues the voucher when a late payment lands on an expired purchase', function (): void {
        $order = gvCardOrder();
        $this->travel(2)->days();
        $this->stripe()->sessions[(string) $order->stripe_checkout_session_id]['status'] = 'expired';
        app(ReleaseExpiredVoucherOrders::class)->handle();
        expect($order->refresh()->status)->toBe(VoucherOrderStatus::Expired);

        gvWebhook($this, FakeStripeGateway::event('checkout.session.completed', gvPaidSession($order)))->assertOk();

        expect($order->refresh()->status)->toBe(VoucherOrderStatus::Paid)
            ->and(Voucher::query()->count())->toBe(1);
    });

    it('refunds a payment for a cancelled purchase automatically', function (): void {
        $order = gvCardOrder();
        app(VoucherOrderStateMachine::class)->transition($order, VoucherOrderStatus::Cancelled, reason: 'Customer asked');

        gvWebhook($this, FakeStripeGateway::event('checkout.session.completed', gvPaidSession($order)))->assertOk();

        expect(Voucher::query()->exists())->toBeFalse()
            ->and($this->stripe()->refunds)->toHaveCount(1)
            ->and($this->stripe()->refunds[0]['params']['amount'])->toBe(500_000)
            ->and($order->refresh()->needs_review)->toBeTrue();
    });

    it('keeps the purchase open after a failed or expired session', function (string $type): void {
        $order = gvCardOrder();

        gvWebhook($this, FakeStripeGateway::event($type, gvPaidSession($order, ['payment_status' => 'unpaid', 'status' => 'expired'])))->assertOk();

        $order->refresh();
        expect($order->status)->toBe(VoucherOrderStatus::PendingPayment)
            ->and($order->stripe_checkout_session_id)->toBeNull()
            ->and(Voucher::query()->exists())->toBeFalse();
    })->with(['checkout.session.async_payment_failed', 'checkout.session.expired']);

    it('voids the unused balance when the payment is refunded in Stripe', function (): void {
        $order = gvCardOrder();
        gvWebhook($this, FakeStripeGateway::event('checkout.session.completed', gvPaidSession($order)))->assertOk();
        $order->refresh();

        $charge = ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => $order->stripe_payment_intent_id, 'amount_refunded' => 500_000];
        gvWebhook($this, FakeStripeGateway::event('charge.refunded', $charge))->assertOk();
        gvWebhook($this, FakeStripeGateway::event('charge.refunded', $charge))->assertOk();

        $voucher = Voucher::query()->sole();
        expect($order->refresh()->status)->toBe(VoucherOrderStatus::Refunded)
            ->and($order->needs_review)->toBeTrue()
            ->and($voucher->status)->toBe(VoucherStatus::Void)
            ->and($voucher->balance_minor)->toBe(0)
            ->and($voucher->ledger()->where('reason', VoucherLedgerReason::Voided)->count())->toBe(1);
    });

    it('records each event once', function (): void {
        $order = gvCardOrder();

        gvWebhook($this, FakeStripeGateway::event('checkout.session.completed', gvPaidSession($order)))->assertOk();

        expect(StripeEvent::query()->sole()->processed_at)->not->toBeNull();
    });

    it('issues the voucher only once even if called twice', function (): void {
        $order = gvOrder();

        $first = app(IssueVoucher::class)->handle($order);
        $second = app(IssueVoucher::class)->handle($order);

        expect($second->id)->toBe($first->id)->and(Voucher::query()->count())->toBe(1);
    });
});

describe('closing unpaid purchases', function (): void {
    it('expires card purchases after 24 hours and leaves completed sessions to the webhook', function (): void {
        $open = gvCardOrder();
        $paid = gvCardOrder();
        $this->stripe()->complete((string) $paid->stripe_checkout_session_id);

        $this->travel(25)->hours();

        expect(app(ReleaseExpiredVoucherOrders::class)->handle())->toBe(1)
            ->and($open->refresh()->status)->toBe(VoucherOrderStatus::Expired)
            ->and($paid->refresh()->status)->toBe(VoucherOrderStatus::PendingPayment)
            ->and($this->stripe()->expired)->toContain($open->stripe_checkout_session_id);
    });
});

describe('access', function (): void {
    it('shows a purchase only to its buyer', function (): void {
        $order = gvOrder();

        $this->actingAs(User::factory()->create())->get(route('gift-vouchers.show', $order->reference))->assertNotFound();
        $this->actingAs($order->user)->get(route('gift-vouchers.show', $order->reference))->assertOk()->assertSee($order->reference);
        $this->actingAs($order->user)->get(route('account.vouchers'))->assertOk()->assertSee('Sara Khan');
    });

    it('hands over to Stripe from the pay button', function (): void {
        $order = gvOrder();

        $this->actingAs($order->user)->post(route('gift-vouchers.card', $order->reference))
            ->assertRedirectContains('https://checkout.stripe.com/');
    });

    it('shows the status page while the webhook confirms', function (): void {
        $order = gvCardOrder();

        $this->actingAs($order->user)->get(route('gift-vouchers.status', $order->reference))->assertOk()->assertSee('Confirming your payment');
    });
});
