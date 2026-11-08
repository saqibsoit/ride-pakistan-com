<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Actions\Payments\CreateStripeCheckout;
use App\Enums\PaymentMethod;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\VoucherRejected;
use App\Models\VoucherOrder;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Throwable;

/**
 * Card payment for a gift voucher purchase (milestone 10e): a Stripe
 * Checkout Session in PKR for the purchase amount (from the record, never
 * the request). metadata.kind = "voucher" routes its webhook events to
 * HandleVoucherStripeEvent, so order fulfilment never sees it. An open
 * session is reused; an expired one is replaced (new idempotency key).
 */
final class CreateVoucherCheckout
{
    public const KIND = 'voucher';

    public const SESSION_MINUTES = 60;

    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * @return string the Stripe-hosted Checkout URL
     *
     * @throws VoucherRejected
     */
    public function handle(VoucherOrder $order): string
    {
        $reusable = $this->openSession($order);

        if ($reusable !== null) {
            return (string) $reusable->url;
        }

        [$order, $expiresAt, $attempt] = $this->prepare($order);

        try {
            $session = $this->stripe->createCheckoutSession(
                $this->params($order, $expiresAt),
                'voucher-checkout:'.$order->id.':'.$attempt,
            );
        } catch (Throwable $e) {
            Log::error('Stripe Checkout Session failed for voucher purchase '.$order->reference, ['error' => $e->getMessage()]);

            throw new VoucherRejected('Card payment is not available right now. Please try again in a minute, or pay by bank transfer.');
        }

        $order->stripe_checkout_session_id = $session->id;
        $order->save();

        activity('vouchers')
            ->performedOn($order)
            ->causedBy($order->user)
            ->event('card_checkout_started')
            ->withProperties(['session' => $session->id, 'attempt' => $attempt])
            ->log('Card payment started for voucher purchase '.$order->reference);

        return (string) $session->url;
    }

    /** @throws VoucherRejected */
    private function openSession(VoucherOrder $order): ?Session
    {
        $order->refresh();

        if ($order->status === VoucherOrderStatus::Paid) {
            throw VoucherRejected::alreadyPaid();
        }

        if ($order->status !== VoucherOrderStatus::PendingPayment || $order->expires_at === null || ! $order->expires_at->isFuture()) {
            throw VoucherRejected::notPayable();
        }

        if ($order->stripe_checkout_session_id === null) {
            return null;
        }

        try {
            $session = $this->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id);
        } catch (Throwable) {
            return null;
        }

        return match ($session->status) {
            Session::STATUS_OPEN => $session,
            Session::STATUS_COMPLETE => throw VoucherRejected::alreadyPaid(),
            default => null,
        };
    }

    /**
     * @return array{0: VoucherOrder, 1: CarbonImmutable, 2: int}
     *
     * @throws VoucherRejected
     */
    private function prepare(VoucherOrder $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = VoucherOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if ($locked->status !== VoucherOrderStatus::PendingPayment || $locked->expires_at === null || ! $locked->expires_at->isAfter($now)) {
                throw VoucherRejected::notPayable();
            }

            // The purchase stays open at least as long as its Stripe session.
            $expiresAt = $now->addMinutes(self::SESSION_MINUTES);

            if ($locked->expires_at->isBefore($expiresAt)) {
                $locked->expires_at = $expiresAt;
            }

            $meta = $locked->meta ?? [];
            $attempt = (int) ($meta['stripe_attempts'] ?? 0) + 1;
            $meta['stripe_attempts'] = $attempt;

            $locked->meta = $meta;
            $locked->payment_method = PaymentMethod::Card;
            $locked->save();

            $order->setRawAttributes($locked->getAttributes(), true);

            return [$order, $expiresAt, $attempt];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function params(VoucherOrder $order, CarbonImmutable $expiresAt): array
    {
        $order->loadMissing('user');

        $metadata = ['kind' => self::KIND, 'voucher_order_id' => (string) $order->id, 'reference' => $order->reference];
        $statusUrl = route('gift-vouchers.status', ['reference' => $order->reference]);
        $suffix = trim((string) config('services.stripe.statement_descriptor_suffix'));

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'pkr',
                    'unit_amount' => $order->amount_minor,
                    'product_data' => [
                        'name' => $order->label(),
                        'description' => 'For '.$order->recipient_name.'. Valid '.app(VoucherRules::class)->validityMonths().' months from payment.',
                    ],
                ],
            ]],
            'client_reference_id' => self::KIND.':'.$order->id,
            'metadata' => $metadata,
            'payment_intent_data' => array_filter([
                'description' => CreateStripeCheckout::BRAND_LINE.', gift voucher '.$order->reference,
                'metadata' => $metadata,
                'statement_descriptor_suffix' => $suffix === '' ? null : mb_substr($suffix, 0, 22),
            ]),
            'success_url' => $statusUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $statusUrl.'?cancelled=1',
            'expires_at' => $expiresAt->getTimestamp(),
            'custom_text' => [
                'submit' => ['message' => CreateStripeCheckout::BRAND_LINE.'. Gift voucher reference '.$order->reference.'.'],
            ],
        ];

        $email = (string) $order->user->email;

        if ($email !== '' && ! str_ends_with($email, '.invalid')) {
            $params['customer_email'] = $email;
        }

        return $params;
    }
}
