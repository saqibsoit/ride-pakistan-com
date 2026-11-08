<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Models\BookingHold;
use App\Models\Order;
use App\Support\PageCache;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Throwable;

/**
 * Every minute (holds:release). Card orders whose hold window passed
 * become expired (logged through OrderStateMachine) and give their seats
 * back. Expired holds already stop counting the moment they expire; this
 * tidies them up and refreshes the cached public pages. Holds on bank
 * transfer orders are handled by the transfer flow (milestone 7).
 *
 * An order with a Stripe Checkout Session has the session expired first,
 * so the customer can't pay after the seats are gone. If Stripe says the
 * session was already paid, the order is left for the webhook.
 */
final class ReleaseExpiredHolds
{
    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly PageCache $pageCache,
        private readonly StripeGateway $stripe,
    ) {}

    /** @return int orders expired */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $expired = 0;

        Order::query()
            ->where('status', OrderStatus::PendingPayment)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->each(function (Order $order) use (&$expired, $now): void {
                if (! $this->closeStripeSession($order, $now)) {
                    return;
                }

                DB::transaction(function () use ($order, &$expired): void {
                    $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

                    // Paid (or otherwise moved on) since we looked: leave it.
                    if ($locked === null || $locked->status !== OrderStatus::PendingPayment) {
                        return;
                    }

                    $this->orders->transition($locked, OrderStatus::Expired, reason: 'Seat hold expired before payment');
                    BookingHold::query()->where('order_id', $locked->id)->delete();
                    $expired++;
                });
            });

        // Stray expired holds whose order no longer waits for payment.
        $stray = BookingHold::query()
            ->where('expires_at', '<=', $now)
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', [OrderStatus::AwaitingTransfer, OrderStatus::AwaitingVerification]))
            ->delete();

        if ($expired > 0 || $stray > 0) {
            $this->pageCache->invalidate();
        }

        return $expired;
    }

    /** @return bool whether the order may be expired now */
    private function closeStripeSession(Order $order, CarbonImmutable $now): bool
    {
        if ($order->stripe_checkout_session_id === null) {
            return true;
        }

        try {
            $session = $this->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id);

            if ($session->status === Session::STATUS_COMPLETE) {
                return false; // paid: the webhook fulfils (or refunds) it
            }

            if ($session->status === Session::STATUS_OPEN) {
                $this->stripe->expireCheckoutSession($session->id);
            }

            return true;
        } catch (Throwable $e) {
            // Sessions never outlive 24 hours, so past that it is safe anyway.
            if ($order->expires_at !== null && $order->expires_at->addDay()->isBefore($now)) {
                return true;
            }

            Log::warning('Could not close the Stripe session for order '.$order->reference.'; retrying next run', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
