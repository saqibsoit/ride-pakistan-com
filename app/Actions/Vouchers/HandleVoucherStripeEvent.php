<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Enums\VoucherOrderStatus;
use App\Exceptions\VoucherRejected;
use App\Models\VoucherOrder;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Throwable;

/**
 * Stripe events for gift voucher purchases (milestone 10e). Called only by
 * HandleStripeEvent, inside its transaction and event-id idempotency, for
 * sessions whose metadata.kind is "voucher" and for refunds of a voucher
 * payment. Order fulfilment never sees these events.
 *
 * - paid session: purchase, reference, amount and currency re-checked; a
 *   mismatch is never issued (critical log, audit, "Needs review"). Then
 *   IssueVoucher. A payment for a cancelled or rejected purchase is
 *   refunded automatically and flagged.
 * - failed / expired session: the purchase stays open (try again or pay by
 *   transfer); vouchers:release closes it later.
 * - charge.refunded: the purchase is marked refunded and the voucher's
 *   unused balance voided, then flagged for the team.
 */
final class HandleVoucherStripeEvent
{
    public function __construct(
        private readonly IssueVoucher $issue,
        private readonly VoucherOrderStateMachine $orders,
        private readonly VoucherBalance $balance,
        private readonly StripeGateway $stripe,
    ) {}

    /**
     * @param  array<string, mixed>  $object
     */
    public static function isVoucherSession(array $object): bool
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        return ($metadata['kind'] ?? null) === CreateVoucherCheckout::KIND;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function sessionPaid(Event $event, array $session): void
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            return;
        }

        $order = $this->orderFor($event, $session);

        if ($order === null) {
            return;
        }

        $amount = self::int($session['amount_total'] ?? null);
        $currency = strtolower(self::str($session['currency'] ?? null));
        $intent = self::str($session['payment_intent'] ?? null);
        $context = ['stripe_event_id' => $event->id, 'stripe_session' => self::str($session['id'] ?? null)];

        $this->recordPayment($order, $intent, $amount, $currency, 'succeeded', $event);

        if ($amount !== $order->amount_minor || $currency !== strtolower($order->currency)) {
            $this->flag($order, 'payment_mismatch', sprintf(
                'Stripe paid %s %d but the voucher purchase is %s %d. No voucher issued.',
                strtoupper($currency), $amount, $order->currency, $order->amount_minor,
            ), $context);

            return;
        }

        try {
            $this->issue->handle($order, context: $context);
        } catch (VoucherRejected $e) {
            $this->refundClosed($order, $intent, $amount, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function sessionFailed(Event $event, array $session, string $how): void
    {
        $order = $this->orderFor($event, $session);

        if ($order === null) {
            return;
        }

        $sessionId = self::str($session['id'] ?? null);

        if ($how === 'failed') {
            $this->recordPayment($order, self::str($session['payment_intent'] ?? null), self::int($session['amount_total'] ?? null), strtolower(self::str($session['currency'] ?? null)), 'failed', $event);
        }

        if ($order->stripe_checkout_session_id === $sessionId) {
            $order->stripe_checkout_session_id = null;
            $order->save();
        }

        activity('vouchers')
            ->performedOn($order)
            ->event('card_'.$how)
            ->withProperties(['stripe_event_id' => $event->id, 'stripe_session' => $sessionId, 'status' => $order->status->value])
            ->log('Card payment '.$how.' for voucher purchase '.$order->reference);
    }

    /**
     * A refund made in the Stripe dashboard for a voucher payment.
     *
     * @param  array<string, mixed>  $charge
     * @return bool false when the charge isn't a voucher payment
     */
    public function chargeRefunded(Event $event, array $charge): bool
    {
        $intent = self::str($charge['payment_intent'] ?? null);
        $order = $intent === '' ? null : VoucherOrder::query()->where('stripe_payment_intent_id', $intent)->lockForUpdate()->first();

        if ($order === null) {
            return false;
        }

        $refunded = self::int($charge['amount_refunded'] ?? null);
        $context = ['stripe_event_id' => $event->id, 'stripe_charge' => self::str($charge['id'] ?? null)];
        $meta = $order->meta ?? [];

        if ($refunded <= (int) ($meta['amount_refunded_minor'] ?? 0)) {
            return true; // already recorded
        }

        $meta['amount_refunded_minor'] = $refunded;
        $order->meta = $meta;
        $order->save();

        if ($order->status === VoucherOrderStatus::Paid) {
            $this->orders->transition($order, VoucherOrderStatus::Refunded, reason: 'Refunded in the Stripe dashboard', context: $context);
        }

        $voucher = $order->voucher()->first();

        if ($voucher !== null) {
            $this->balance->void($voucher, null, 'Purchase '.$order->reference.' refunded in Stripe');
        }

        $this->flag($order, 'refund_outside_admin', sprintf(
            'A refund of %s %d was made in Stripe. The voucher\'s unused balance was voided; check whether any of it had been spent.',
            $order->currency, $refunded,
        ), $context);

        return true;
    }

    /**
     * Resolves and locks the purchase; client_reference_id and
     * metadata.reference must agree.
     *
     * @param  array<string, mixed>  $session
     */
    private function orderFor(Event $event, array $session): ?VoucherOrder
    {
        $clientReference = self::str($session['client_reference_id'] ?? null);
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $reference = self::str($metadata['reference'] ?? null);
        $prefix = CreateVoucherCheckout::KIND.':';
        $id = str_starts_with($clientReference, $prefix) ? substr($clientReference, strlen($prefix)) : '';

        $order = ctype_digit($id) ? VoucherOrder::query()->whereKey((int) $id)->lockForUpdate()->first() : null;

        if ($order === null) {
            Log::critical('Stripe session for an unknown voucher purchase', ['stripe_event_id' => $event->id, 'client_reference_id' => $clientReference, 'reference' => $reference]);

            return null;
        }

        if ($order->reference !== $reference) {
            $this->flag($order, 'payment_mismatch', 'Stripe session reference "'.$reference.'" does not match this voucher purchase. No voucher issued.', [
                'stripe_event_id' => $event->id,
                'stripe_session' => self::str($session['id'] ?? null),
            ]);

            return null;
        }

        return $order;
    }

    /** Payments are kept on the purchase (the payments table belongs to orders). */
    private function recordPayment(VoucherOrder $order, string $intent, int $amount, string $currency, string $status, Event $event): void
    {
        $meta = $order->meta ?? [];
        /** @var list<array<string, mixed>> $payments */
        $payments = is_array($meta['payments'] ?? null) ? $meta['payments'] : [];

        foreach ($payments as $payment) {
            if ($intent !== '' && ($payment['provider_id'] ?? null) === $intent && ($payment['status'] ?? null) === $status) {
                return;
            }
        }

        $payments[] = [
            'provider' => 'stripe',
            'provider_id' => $intent !== '' ? $intent : null,
            'amount_minor' => $amount,
            'currency' => strtoupper($currency !== '' ? $currency : $order->currency),
            'status' => $status,
            'event' => $event->id,
            'at' => CarbonImmutable::now()->toIso8601String(),
        ];

        $meta['payments'] = $payments;
        $order->meta = $meta;

        if ($status === 'succeeded' && $intent !== '') {
            $order->stripe_payment_intent_id = $intent;
        }

        $order->save();
    }

    /**
     * Money arrived for a purchase that was cancelled or rejected: give it
     * back and tell the team.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function refundClosed(VoucherOrder $order, string $intent, int $amount, array $context): void
    {
        $order->refresh();
        $why = 'purchase '.strtolower($order->status->label());

        try {
            $refund = $this->stripe->createRefund([
                'payment_intent' => $intent,
                'amount' => $amount,
                'metadata' => ['voucher_order_id' => (string) $order->id, 'reference' => $order->reference, 'automatic' => 'unfulfillable'],
            ], 'refund:auto:voucher:'.$order->id.':'.$intent);
        } catch (Throwable $e) {
            $this->flag($order, 'refund_failed', 'Paid but no voucher could be issued ('.$why.'), and the automatic refund failed: '.$e->getMessage(), $context);

            return;
        }

        $meta = $order->meta ?? [];
        $meta['amount_refunded_minor'] = (int) ($meta['amount_refunded_minor'] ?? 0) + $amount;
        $order->meta = $meta;
        $order->save();

        $this->flag($order, 'payment_refunded_unfulfillable', 'Paid but no voucher could be issued ('.$why.'). Refunded automatically.', [...$context, 'stripe_refund' => $refund->id]);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function flag(VoucherOrder $order, string $event, string $message, array $context): void
    {
        $order->needs_review = true;
        $meta = $order->meta ?? [];
        $meta['review_reason'] = $message;
        $order->meta = $meta;
        $order->save();

        Log::critical('Voucher purchase '.$order->reference.': '.$message, $context);

        activity('vouchers')
            ->performedOn($order)
            ->event($event)
            ->withProperties([...$context, 'message' => $message])
            ->log($message);
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
