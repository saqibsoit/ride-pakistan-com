<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Booking\SlotLock;
use App\Actions\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\CheckoutUnavailable;
use App\Models\BookingHold;
use App\Models\Order;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Throwable;

/**
 * The customer chooses to pay by bank transfer (PLAN §3.2 step 1). The
 * order keeps its reference, also after a failed or expired card attempt.
 *
 * Any open Stripe session is expired first, so no card payment can land
 * on an order that is waiting for a transfer. The order then moves to
 * awaiting_transfer and its seats are held until the transfer deadline
 * (TransferWindow). Choosing again is harmless.
 */
final class ChooseBankTransfer
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly SlotLock $slotLock,
        private readonly OrderStateMachine $orders,
        private readonly TransferWindow $window,
    ) {}

    /** @throws CheckoutUnavailable */
    public function handle(Order $order): Order
    {
        $order->refresh();

        if (in_array($order->status, [OrderStatus::AwaitingTransfer, OrderStatus::AwaitingVerification], true)) {
            return $order;
        }

        $this->assertPayable($order, CarbonImmutable::now());

        if (! $this->window->isOffered($order)) {
            throw CheckoutUnavailable::transferTooLate($this->window->minNoticeHours());
        }

        $this->closeStripeSession($order);

        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if (in_array($locked->status, [OrderStatus::AwaitingTransfer, OrderStatus::AwaitingVerification], true)) {
                $order->setRawAttributes($locked->getAttributes(), true);

                return $order;
            }

            $this->assertPayable($locked, $now);

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

            $deadline = $this->window->deadline($locked, $now);

            BookingHold::query()->where('order_id', $locked->id)->update(['expires_at' => $deadline]);

            $locked->expires_at = $deadline;
            $locked->payment_method = PaymentMethod::BankTransfer;
            $locked->stripe_checkout_session_id = null;
            $locked->save();

            $this->orders->transition($locked, OrderStatus::AwaitingTransfer, $locked->user, context: ['deadline' => $deadline->toIso8601String()]);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    /** @throws CheckoutUnavailable */
    private function assertPayable(Order $order, CarbonImmutable $now): void
    {
        if ($order->status === OrderStatus::Paid) {
            throw CheckoutUnavailable::alreadyPaid();
        }

        if ($order->status !== OrderStatus::PendingPayment || $order->expires_at === null || ! $order->expires_at->isAfter($now)) {
            throw CheckoutUnavailable::notPayable();
        }

        // Discounts cover everything: confirm it instead (milestone 10e Part B).
        if ($order->amount_minor === 0) {
            throw CheckoutUnavailable::nothingToPay();
        }
    }

    /**
     * Expires the order's Stripe session so it can't be paid by card any
     * more. A session Stripe already completed means the card payment went
     * through: the webhook confirms it.
     *
     * @throws CheckoutUnavailable
     */
    private function closeStripeSession(Order $order): void
    {
        if ($order->stripe_checkout_session_id === null) {
            return;
        }

        try {
            $session = $this->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id);

            if ($session->status === Session::STATUS_COMPLETE) {
                throw CheckoutUnavailable::alreadyPaid();
            }

            if ($session->status === Session::STATUS_OPEN) {
                $this->stripe->expireCheckoutSession($session->id);
            }
        } catch (CheckoutUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Could not close the Stripe session before bank transfer for order '.$order->reference, ['error' => $e->getMessage()]);

            throw CheckoutUnavailable::cardPageStillOpen();
        }
    }
}
