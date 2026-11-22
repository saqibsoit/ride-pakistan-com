<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Payments\ApplyRefund;
use App\Actions\Payments\FlagOrderForReview;
use App\Enums\BalanceMethod;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Exceptions\BookingRejected;
use App\Models\Order;
use App\Models\OrderBalance;
use App\Models\User;
use App\Support\Money;
use App\Support\Stripe\StripeGateway;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives event money back (decided 2026-11-22), for a customer cancellation
 * before the cutoff or a date we cancelled. Card money goes back through
 * Stripe straight away: the deposit (or full payment) through the order's
 * refund rules (ApplyRefund: refund record, order status, gift voucher and
 * account credit share), a balance paid online through its own payment.
 * Money paid by bank transfer or at the venue can't be sent back by the
 * system: the order is flagged "Needs review" with the amount, for an admin
 * to pay it back and record it.
 *
 * Runs inside the caller's transaction with the order locked; the event's
 * bookings must already be cancelled (ApplyRefund then finds none to cancel).
 */
final class RefundEventMoney
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly ApplyRefund $refund,
        private readonly FlagOrderForReview $flag,
    ) {}

    /**
     * @return array{automatic: int, manual: int} paisa refunded now, and left for an admin
     *
     * @throws BookingRejected when Stripe refuses (nothing is written: the caller's transaction rolls back)
     */
    public function handle(Order $order, ?OrderBalance $balance, int $fromOrderMinor, int $fromBalanceMinor, User $actor, string $reason): array
    {
        $automatic = 0;
        $manual = 0;

        if ($fromBalanceMinor > 0 && $balance !== null) {
            if ($balance->method === BalanceMethod::CardOnline && $balance->stripe_payment_intent_id !== null) {
                $refund = $this->stripeRefund($balance->stripe_payment_intent_id, $fromBalanceMinor, 'balance-refund:'.$balance->id.':'.$balance->refunded_minor.':'.$fromBalanceMinor, $order);
                RecordBalanceRefund::apply($balance, $order, $fromBalanceMinor, $refund, PaymentProvider::Stripe, $reason, $actor);
                $automatic += $fromBalanceMinor;
            } else {
                $manual += $fromBalanceMinor;
            }
        }

        if ($fromOrderMinor > 0) {
            if ($order->payment_method === PaymentMethod::Card && $order->stripe_payment_intent_id !== null) {
                $intent = $order->stripe_payment_intent_id;
                $key = 'refund:'.$order->id.':'.$order->amount_refunded_minor.':'.$fromOrderMinor;

                $this->refund->handle($order, $actor, $fromOrderMinor, $reason, [], 0, PaymentProvider::Stripe, function () use ($intent, $fromOrderMinor, $key, $order): array {
                    $id = $this->stripeRefund($intent, $fromOrderMinor, $key, $order);

                    return ['provider_id' => $id, 'raw' => ['id' => $id], 'context' => ['stripe_refund' => $id]];
                });

                $automatic += $fromOrderMinor;
            } else {
                $manual += $fromOrderMinor;
            }
        }

        if ($manual > 0) {
            $this->flag->handle($order, 'event_refund_due', 'Refund '.Money::format($manual, $order->currency).' by bank transfer or cash ('.$reason.'), then record it on this order.', ['refund_minor' => $manual]);
        }

        return ['automatic' => $automatic, 'manual' => $manual];
    }

    /**
     * @throws BookingRejected
     */
    private function stripeRefund(string $intent, int $amountMinor, string $key, Order $order): string
    {
        try {
            return $this->stripe->createRefund([
                'payment_intent' => $intent,
                'amount' => $amountMinor,
                'reason' => 'requested_by_customer',
                'metadata' => ['order_id' => (string) $order->id, 'reference' => $order->reference],
            ], $key)->id;
        } catch (Throwable $e) {
            Log::error('Stripe refund failed for event order '.$order->reference, ['error' => $e->getMessage()]);

            throw BookingRejected::notAllowed('We couldn’t send your refund just now, so nothing was cancelled. Please try again in a few minutes or contact us.');
        }
    }
}
