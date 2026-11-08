<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Booking\FulfilOrder;
use App\Actions\Discounts\RestoreOrderDiscounts;
use App\Actions\Vouchers\HandleVoucherStripeEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Exceptions\BookingRejected;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StripeEvent;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Throwable;

/**
 * Processes one verified Stripe webhook event (PLAN §3.1). Runs in one
 * transaction and is idempotent on the event id (stripe_events): a
 * redelivered event changes nothing. If anything throws, everything rolls
 * back and the controller answers 500, so Stripe retries.
 *
 * - checkout.session.completed (paid) / async_payment_succeeded: the order,
 *   amount and currency are re-checked against the session, the payment is
 *   recorded and FulfilOrder runs. A mismatch is never fulfilled (alert +
 *   review flag). A payment that can no longer be fulfilled (slot gone,
 *   order expired) is refunded automatically.
 * - async_payment_failed / checkout.session.expired: the order stays open
 *   while its hold lasts, so the customer can try a card again or pay by
 *   bank transfer under the same reference; holds:release expires it.
 * - charge.refunded: refunds not yet recorded (made in the Stripe
 *   dashboard) are recorded and the order is flagged for review.
 *
 * Gift voucher purchases (milestone 10e, metadata.kind = "voucher", and
 * refunds of their payments) go to HandleVoucherStripeEvent instead and
 * never reach FulfilOrder.
 */
final class HandleStripeEvent
{
    public const HANDLED = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        'charge.refunded',
    ];

    public function __construct(
        private readonly FulfilOrder $fulfil,
        private readonly RecordRefund $refunds,
        private readonly FlagOrderForReview $flag,
        private readonly StripeGateway $stripe,
        private readonly HandleVoucherStripeEvent $vouchers,
        private readonly RestoreOrderDiscounts $restore,
    ) {}

    /** @return bool false when the event had already been processed */
    public function handle(Event $event): bool
    {
        return DB::transaction(function () use ($event): bool {
            $record = StripeEvent::query()->where('event_id', $event->id)->lockForUpdate()->first();

            if ($record?->processed_at !== null) {
                return false;
            }

            // A concurrent delivery of the same event fails the unique index
            // here and is retried by Stripe (by then this one is processed).
            $record ??= StripeEvent::query()->create(['event_id' => $event->id, 'type' => $event->type]);

            /** @var array<string, mixed> $object */
            $object = $event->data->object->toArray();

            if (str_starts_with($event->type, 'checkout.session.') && HandleVoucherStripeEvent::isVoucherSession($object)) {
                // Gift voucher purchases (milestone 10e) never reach order fulfilment.
                match ($event->type) {
                    'checkout.session.completed',
                    'checkout.session.async_payment_succeeded' => $this->vouchers->sessionPaid($event, $object),
                    'checkout.session.async_payment_failed' => $this->vouchers->sessionFailed($event, $object, 'failed'),
                    'checkout.session.expired' => $this->vouchers->sessionFailed($event, $object, 'expired'),
                    default => null,
                };
            } else {
                match ($event->type) {
                    'checkout.session.completed',
                    'checkout.session.async_payment_succeeded' => $this->sessionPaid($event, $object),
                    'checkout.session.async_payment_failed' => $this->sessionFailed($event, $object, 'failed'),
                    'checkout.session.expired' => $this->sessionFailed($event, $object, 'expired'),
                    'charge.refunded' => $this->chargeRefunded($event, $object),
                    default => null,
                };
            }

            $record->processed_at = CarbonImmutable::now();
            $record->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionPaid(Event $event, array $session): void
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            return; // async method still pending: async_payment_succeeded follows
        }

        $order = $this->orderFor($event, $session);

        if ($order === null) {
            return;
        }

        $amount = self::int($session['amount_total'] ?? null);
        $currency = strtolower(self::str($session['currency'] ?? null));
        $intent = self::str($session['payment_intent'] ?? null);
        $context = ['stripe_event_id' => $event->id, 'stripe_session' => self::str($session['id'] ?? null)];

        $this->recordPayment($order, $intent, $amount, $currency, PaymentStatus::Succeeded, $event, $session);

        if ($intent !== '' && $order->stripe_payment_intent_id !== $intent) {
            $order->stripe_payment_intent_id = $intent;
            $order->save();
        }

        if ($amount !== $order->amount_minor || $currency !== strtolower($order->currency)) {
            $this->flag->handle($order, 'payment_mismatch', sprintf(
                'Stripe paid %s %d but the order is %s %d. Not fulfilled.',
                strtoupper($currency), $amount, $order->currency, $order->amount_minor,
            ), $context);

            return;
        }

        $wasUnpaid = $order->status->isUnpaid();

        try {
            // Savepoint: a rejected fulfilment rolls back only its own writes.
            $this->fulfil->handle($order, context: $context);
        } catch (BookingRejected $e) {
            $this->refundUnfulfillable($order, $intent, $amount, $e->getMessage(), $context);

            return;
        }

        if ($wasUnpaid && $order->status === OrderStatus::Paid) {
            event(new OrderPaid($order));
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionFailed(Event $event, array $session, string $how): void
    {
        $order = $this->orderFor($event, $session);

        if ($order === null) {
            return;
        }

        $sessionId = self::str($session['id'] ?? null);

        if ($how === 'failed') {
            $this->recordPayment($order, self::str($session['payment_intent'] ?? null), self::int($session['amount_total'] ?? null), strtolower(self::str($session['currency'] ?? null)), PaymentStatus::Failed, $event, $session);
        }

        // Forget the dead session; a new card attempt starts a fresh one.
        if ($order->stripe_checkout_session_id === $sessionId) {
            $order->stripe_checkout_session_id = null;
            $order->save();
        }

        activity('payments')
            ->performedOn($order)
            ->event('card_'.$how)
            ->withProperties(['stripe_event_id' => $event->id, 'stripe_session' => $sessionId, 'status' => $order->status->value])
            ->log('Card payment '.$how.' for order '.$order->reference);
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function chargeRefunded(Event $event, array $charge): void
    {
        $intent = self::str($charge['payment_intent'] ?? null);

        $order = $intent === '' ? null : Order::query()->where('stripe_payment_intent_id', $intent)->lockForUpdate()->first();

        if ($order === null && $this->vouchers->chargeRefunded($event, $charge)) {
            return; // a gift voucher payment (milestone 10e)
        }

        if ($order === null) {
            Log::warning('charge.refunded for an unknown payment intent', ['stripe_event_id' => $event->id, 'payment_intent' => $intent]);

            return;
        }

        $refundedTotal = self::int($charge['amount_refunded'] ?? null);
        $new = $refundedTotal - $order->amount_refunded_minor;

        if ($new <= 0) {
            return; // already recorded (admin refund or automatic refund)
        }

        $context = ['stripe_event_id' => $event->id, 'stripe_charge' => self::str($charge['id'] ?? null)];

        $this->refunds->handle($order, $new, self::str($charge['id'] ?? null) ?: null, 'Refunded in the Stripe dashboard', raw: [
            'event' => $event->id,
            'charge' => $charge['id'] ?? null,
            'amount_refunded' => $refundedTotal,
        ], context: $context);

        // Gift voucher and credit share, as for an admin refund (milestone 10e Part B).
        $this->restore->afterRefund($order, null, 'Refunded in the Stripe dashboard');

        $this->flag->handle($order, 'refund_outside_admin', sprintf(
            'A refund of %s %d was made outside the admin panel. Check bookings and credits.',
            $order->currency, $new,
        ), $context);
    }

    /**
     * Resolves and locks the order a session belongs to: client_reference_id
     * and metadata.reference must agree.
     *
     * @param  array<string, mixed>  $session
     */
    private function orderFor(Event $event, array $session): ?Order
    {
        $id = self::str($session['client_reference_id'] ?? null);
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $reference = self::str($metadata['reference'] ?? null);

        $order = ctype_digit($id) ? Order::query()->whereKey((int) $id)->lockForUpdate()->first() : null;

        if ($order === null) {
            Log::critical('Stripe session for an unknown order', ['stripe_event_id' => $event->id, 'client_reference_id' => $id, 'reference' => $reference]);

            return null;
        }

        if ($order->reference !== $reference) {
            $this->flag->handle($order, 'payment_mismatch', 'Stripe session reference "'.$reference.'" does not match this order. Not fulfilled.', [
                'stripe_event_id' => $event->id,
                'stripe_session' => self::str($session['id'] ?? null),
            ]);

            return null;
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function recordPayment(Order $order, string $intent, int $amount, string $currency, PaymentStatus $status, Event $event, array $session): void
    {
        $exists = $intent !== '' && Payment::query()
            ->where('order_id', $order->id)
            ->where('provider', PaymentProvider::Stripe)
            ->where('provider_id', $intent)
            ->where('status', $status)
            ->exists();

        if ($exists) {
            return;
        }

        Payment::query()->create([
            'order_id' => $order->id,
            'provider' => PaymentProvider::Stripe,
            'provider_id' => $intent !== '' ? $intent : null,
            'amount_minor' => $amount,
            'currency' => strtoupper($currency !== '' ? $currency : $order->currency),
            'status' => $status,
            'raw' => [
                'event' => $event->id,
                'type' => $event->type,
                'session' => $session['id'] ?? null,
                'payment_status' => $session['payment_status'] ?? null,
                'amount_total' => $session['amount_total'] ?? null,
                'currency' => $session['currency'] ?? null,
            ],
        ]);
    }

    /**
     * Money arrived for an order that can't be fulfilled any more: give it
     * back straight away and tell the team.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function refundUnfulfillable(Order $order, string $intent, int $amount, string $why, array $context): void
    {
        $order->refresh();

        try {
            $refund = $this->stripe->createRefund([
                'payment_intent' => $intent,
                'amount' => $amount,
                'metadata' => ['order_id' => (string) $order->id, 'reference' => $order->reference, 'automatic' => 'unfulfillable'],
            ], 'refund:auto:'.$order->id.':'.$intent);
        } catch (Throwable $e) {
            $this->flag->handle($order, 'refund_failed', 'Paid but could not be fulfilled ('.$why.'), and the automatic refund failed: '.$e->getMessage(), $context);

            return;
        }

        $this->refunds->handle($order, $amount, $refund->id, 'Automatic refund: '.$why, raw: [
            'id' => $refund->id,
            'status' => $refund->status,
        ], context: $context);

        $this->flag->handle($order, 'payment_refunded_unfulfillable', 'Paid but could not be fulfilled ('.$why.'). Refunded automatically.', [...$context, 'stripe_refund' => $refund->id]);
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
