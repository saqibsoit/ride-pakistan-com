<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Payments\FlagOrderForReview;
use App\Enums\BalanceMethod;
use App\Enums\BalanceStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderBalance;
use App\Models\Payment;
use App\Notifications\EventBalancePaid;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * Stripe events for event balance payments (decided 2026-11-22), called by
 * HandleStripeEvent inside its transaction and event-id idempotency. A
 * paid session is checked (reference, amount, currency) and recorded as a
 * balance payment; it never calls FulfilOrder or fires OrderPaid (the
 * deposit confirmed the seats). A payment that arrives for a balance no
 * longer due (paid at the venue meanwhile, or the booking was cancelled)
 * is recorded and the order flagged for an admin to refund.
 */
final class HandleBalanceStripeEvent
{
    public function __construct(
        private readonly FlagOrderForReview $flag,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $object
     */
    public static function isBalanceSession(array $object): bool
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        return ($metadata['kind'] ?? null) === CreateBalanceCheckout::KIND;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function sessionPaid(Event $event, array $session): void
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            return; // async: wait for async_payment_succeeded
        }

        $balance = $this->balanceFor($event, $session);

        if ($balance === null) {
            return;
        }

        $order = Order::query()->whereKey($balance->order_id)->lockForUpdate()->firstOrFail();
        $intent = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : '';
        $amount = is_int($session['amount_total'] ?? null) ? $session['amount_total'] : 0;
        $currency = strtolower(is_string($session['currency'] ?? null) ? $session['currency'] : '');
        $context = ['stripe_event_id' => $event->id, 'stripe_session' => (string) ($session['id'] ?? '')];

        if ($intent !== '' && Payment::query()->where('provider', PaymentProvider::Stripe)->where('provider_id', $intent)->exists()) {
            return; // already recorded
        }

        Payment::query()->create([
            'order_id' => $order->id,
            'purpose' => PaymentPurpose::Balance,
            'provider' => PaymentProvider::Stripe,
            'provider_id' => $intent !== '' ? $intent : null,
            'amount_minor' => $amount,
            'currency' => strtoupper($currency !== '' ? $currency : $balance->currency),
            'status' => PaymentStatus::Succeeded,
            'raw' => ['event' => $event->id, 'type' => $event->type, 'session' => $session['id'] ?? null, 'amount_total' => $amount, 'currency' => $currency],
        ]);

        if ($amount !== $balance->amount_minor || $currency !== strtolower($balance->currency)) {
            $this->flag->handle($order, 'balance_mismatch', sprintf('Balance payment of %s %d does not match the balance due (%s %d). Not marked paid.', strtoupper($currency), $amount, $balance->currency, $balance->amount_minor), $context);

            return;
        }

        if (! $balance->isDue()) {
            $this->flag->handle($order, 'balance_paid_twice', 'A balance card payment arrived after the balance was '.$balance->status->value.'. Refund it from Stripe.', $context);

            return;
        }

        $balance->status = BalanceStatus::Paid;
        $balance->method = BalanceMethod::CardOnline;
        $balance->paid_at = CarbonImmutable::now();
        $balance->stripe_payment_intent_id = $intent !== '' ? $intent : null;
        $balance->save();

        activity('payments')
            ->performedOn($order)
            ->event('balance_paid')
            ->withProperties([...$context, 'method' => BalanceMethod::CardOnline->value, 'amount_minor' => $amount])
            ->log('Balance paid online for order '.$order->reference);

        $this->notifier->send($order->user, new EventBalancePaid($order, $balance), Notifier::key(EventBalancePaid::type(), $order, $order->user));
    }

    /**
     * A refund of a balance payment made in the Stripe dashboard. Returns
     * false when the payment intent isn't a balance payment.
     *
     * @param  array<string, mixed>  $charge
     */
    public function chargeRefunded(Event $event, array $charge): bool
    {
        $intent = is_string($charge['payment_intent'] ?? null) ? $charge['payment_intent'] : '';
        $balance = $intent === '' ? null : OrderBalance::query()->where('stripe_payment_intent_id', $intent)->lockForUpdate()->first();

        if ($balance === null) {
            return false;
        }

        $refundedTotal = is_int($charge['amount_refunded'] ?? null) ? $charge['amount_refunded'] : 0;
        $new = $refundedTotal - $balance->refunded_minor;

        if ($new <= 0) {
            return true; // recorded already (RefundEventBooking)
        }

        $order = Order::query()->whereKey($balance->order_id)->lockForUpdate()->firstOrFail();

        RecordBalanceRefund::apply($balance, $order, $new, is_string($charge['id'] ?? null) ? $charge['id'] : null, PaymentProvider::Stripe, 'Refunded in the Stripe dashboard', null);

        $this->flag->handle($order, 'refund_outside_admin', 'A balance refund was made in the Stripe dashboard. Check the booking.', ['stripe_event_id' => $event->id]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function balanceFor(Event $event, array $session): ?OrderBalance
    {
        $ref = is_string($session['client_reference_id'] ?? null) ? $session['client_reference_id'] : '';
        $id = str_starts_with($ref, CreateBalanceCheckout::KIND.':') ? substr($ref, strlen(CreateBalanceCheckout::KIND) + 1) : '';
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $balance = ctype_digit($id) ? OrderBalance::query()->whereKey((int) $id)->lockForUpdate()->first() : null;

        if ($balance === null) {
            Log::critical('Stripe balance session for an unknown balance', ['stripe_event_id' => $event->id, 'client_reference_id' => $ref]);

            return null;
        }

        $order = Order::query()->whereKey($balance->order_id)->first();

        if ($order === null || ($metadata['reference'] ?? null) !== $order->reference) {
            Log::critical('Stripe balance session reference mismatch', ['stripe_event_id' => $event->id, 'balance' => $balance->id]);

            return null;
        }

        return $balance;
    }
}
