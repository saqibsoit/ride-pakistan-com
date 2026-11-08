<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Booking\SlotLock;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Exceptions\CheckoutUnavailable;
use App\Models\BookingHold;
use App\Models\Order;
use App\Support\Settings;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Stripe\Checkout\Session;
use Throwable;

/**
 * Starts card payment for an unpaid order (PLAN §3.1): a Stripe Checkout
 * Session in PKR with amounts taken from the order's items (never from the
 * request), the order id as client_reference_id and its reference in the
 * metadata. Nothing is fulfilled here; that is the webhook's job.
 *
 * Stripe keeps a session open for at least 30 minutes, so starting card
 * payment extends the order's seat hold to now + max(card hold, 31 min)
 * and the session expires at exactly that time. An open session for the
 * order is reused; an expired one is replaced with a new attempt (new
 * idempotency key).
 */
final class CreateStripeCheckout
{
    /** Stripe's minimum is 30 minutes after creation; one spare for the round trip. */
    public const MIN_SESSION_MINUTES = 31;

    public const BRAND_LINE = 'Ride Pakistan by Destination Pakistan';

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly SlotLock $slotLock,
        private readonly Settings $settings,
    ) {}

    /**
     * @return string the Stripe-hosted Checkout URL
     *
     * @throws CheckoutUnavailable
     */
    public function handle(Order $order): string
    {
        $reusable = $this->openSession($order);

        if ($reusable !== null) {
            return (string) $reusable->url;
        }

        [$order, $expiresAt, $attempt] = $this->prepare($order);

        try {
            $session = $this->stripe->createCheckoutSession(
                $this->params($order, $expiresAt),
                'checkout:'.$order->id.':'.$attempt,
            );
        } catch (Throwable $e) {
            Log::error('Stripe Checkout Session failed for order '.$order->reference, ['error' => $e->getMessage()]);

            throw CheckoutUnavailable::stripeError();
        }

        $order->stripe_checkout_session_id = $session->id;
        $order->save();

        activity('payments')
            ->performedOn($order)
            ->causedBy($order->user)
            ->event('card_checkout_started')
            ->withProperties(['session' => $session->id, 'attempt' => $attempt, 'expires_at' => $expiresAt->toIso8601String()])
            ->log('Card payment started for order '.$order->reference);

        return (string) $session->url;
    }

    /**
     * The order's current session, if it can still be paid.
     *
     * @throws CheckoutUnavailable
     */
    private function openSession(Order $order): ?Session
    {
        $order->refresh();

        if ($order->status === OrderStatus::Paid) {
            throw CheckoutUnavailable::alreadyPaid();
        }

        if ($order->status !== OrderStatus::PendingPayment || $order->expires_at === null || ! $order->expires_at->isFuture()) {
            throw CheckoutUnavailable::notPayable();
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
            Session::STATUS_COMPLETE => throw CheckoutUnavailable::alreadyPaid(),
            default => null,
        };
    }

    /**
     * Locks the order and its slots, extends the hold and counts the attempt.
     *
     * @return array{0: Order, 1: CarbonImmutable, 2: int}
     *
     * @throws CheckoutUnavailable
     */
    private function prepare(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if ($locked->status !== OrderStatus::PendingPayment || $locked->expires_at === null || ! $locked->expires_at->isAfter($now)) {
                throw CheckoutUnavailable::notPayable();
            }

            $slotIds = $locked->items()->whereNotNull('slot_id')->distinct()->orderBy('slot_id')->pluck('slot_id')->map(fn (mixed $id): int => (int) $id)->all();

            foreach ($slotIds as $slotId) {
                $this->slotLock->acquire($slotId);

                $live = BookingHold::query()
                    ->where('order_id', $locked->id)
                    ->where('slot_id', $slotId)
                    ->where('expires_at', '>', $now)
                    ->lockForUpdate()
                    ->exists();

                if (! $live) {
                    throw CheckoutUnavailable::notPayable();
                }
            }

            $minutes = max($this->settings->int(SettingKey::CardHoldMinutes) ?? 30, self::MIN_SESSION_MINUTES);
            $expiresAt = $now->addMinutes($minutes);

            // Never shorten a hold, and Stripe allows at most 24 hours.
            if ($locked->expires_at->isAfter($expiresAt)) {
                $cap = $now->addHours(23);
                $expiresAt = $locked->expires_at->isAfter($cap) ? $cap : $locked->expires_at;
            }

            BookingHold::query()->where('order_id', $locked->id)->update(['expires_at' => $expiresAt]);

            $meta = $locked->meta ?? [];
            $attempt = (int) ($meta['stripe_attempts'] ?? 0) + 1;
            $meta['stripe_attempts'] = $attempt;

            $locked->meta = $meta;
            $locked->expires_at = $expiresAt;
            $locked->payment_method = PaymentMethod::Card;
            $locked->save();

            $order->setRawAttributes($locked->getAttributes(), true);

            return [$order, $expiresAt, $attempt];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function params(Order $order, CarbonImmutable $expiresAt): array
    {
        $order->load(['product', 'items.slot', 'user']);

        $metadata = ['order_id' => (string) $order->id, 'reference' => $order->reference];
        $statusUrl = route('checkout.status', ['reference' => $order->reference]);
        $suffix = trim((string) config('services.stripe.statement_descriptor_suffix'));

        $params = [
            'mode' => 'payment',
            'line_items' => $this->lineItems($order),
            'client_reference_id' => (string) $order->id,
            'metadata' => $metadata,
            'payment_intent_data' => array_filter([
                'description' => self::BRAND_LINE.', order '.$order->reference,
                'metadata' => $metadata,
                'statement_descriptor_suffix' => $suffix === '' ? null : mb_substr($suffix, 0, 22),
            ]),
            // Stripe substitutes {CHECKOUT_SESSION_ID}; it must not be URL-encoded.
            'success_url' => $statusUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $statusUrl.'?cancelled=1',
            'expires_at' => $expiresAt->getTimestamp(),
            'custom_text' => [
                'submit' => ['message' => self::BRAND_LINE.'. Order reference '.$order->reference.'.'],
            ],
        ];

        $email = (string) $order->user->email;

        if ($email !== '' && ! str_ends_with($email, '.invalid')) {
            $params['customer_email'] = $email;
        }

        return $params;
    }

    /**
     * One line per distinct price, from the order's own items. Their sum
     * must equal the order total.
     *
     * @return list<array<string, mixed>>
     */
    private function lineItems(Order $order): array
    {
        $product = $order->product;
        $slot = $order->items->firstWhere('slot_id', '!=', null)?->slot;

        $name = $product->name;
        $description = $product->type === ProductType::Package
            ? $product->sessions_count.' sessions, valid '.$product->validity_days.' days from payment'
            : ($slot === null ? null : $slot->label().' (Pakistan time)');

        $lines = [];
        $total = 0;

        foreach ($order->items->groupBy('unit_price_minor') as $unit => $items) {
            $unit = (int) $unit;

            if ($unit === 0) {
                continue; // first sessions of a package are paid by its credits
            }

            $quantity = $items->count();
            $total += $unit * $quantity;

            $lines[] = [
                'quantity' => $quantity,
                'price_data' => [
                    'currency' => 'pkr',
                    'unit_amount' => $unit,
                    'product_data' => array_filter([
                        'name' => $name.($product->type === ProductType::Package ? '' : ' (per rider)'),
                        'description' => $description,
                    ]),
                ],
            ];
        }

        if ($total !== $order->amount_minor || $lines === [] || strtoupper($order->currency) !== 'PKR') {
            throw new LogicException('Order '.$order->reference.' items do not add up to its total.');
        }

        return $lines;
    }
}
