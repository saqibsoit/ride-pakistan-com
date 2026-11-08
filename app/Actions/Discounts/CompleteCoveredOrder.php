<?php

declare(strict_types=1);

namespace App\Actions\Discounts;

use App\Actions\Booking\FulfilOrder;
use App\Enums\DiscountKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderPaid;
use App\Exceptions\BookingRejected;
use App\Exceptions\DiscountRejected;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Confirm booking" for an order that gift voucher and/or account credit
 * (and promo or referral discounts) cover completely, so there is nothing
 * to charge (milestone 10e Part B). The third allowed caller of FulfilOrder,
 * next to the Stripe webhook and bank transfer approval (CLAUDE.md): only
 * the order's customer, only a pending order that still comes to PKR 0
 * under lock, with at least one discount line. Same fulfilment, emails and
 * events as a paid order.
 */
final class CompleteCoveredOrder
{
    public function __construct(private readonly FulfilOrder $fulfil) {}

    /**
     * @throws DiscountRejected
     * @throws BookingRejected
     */
    public function handle(Order $order, User $customer): Order
    {
        $paid = DB::transaction(function () use ($order, $customer): bool {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Paid) {
                return false; // pressed twice
            }

            if ($locked->user_id !== $customer->id
                || $locked->status !== OrderStatus::PendingPayment
                || $locked->expires_at === null
                || ! $locked->expires_at->isFuture()) {
                throw DiscountRejected::locked();
            }

            if ($locked->amount_minor !== 0 || ! $locked->discounts()->exists()) {
                throw DiscountRejected::because('This order still has an amount to pay.');
            }

            $locked->payment_method = PaymentMethod::Discounts;
            $locked->save();

            $kinds = $locked->discounts()->pluck('kind')->map(fn (mixed $k): string => $k instanceof DiscountKind ? $k->value : (string) $k)->implode(',');
            $this->fulfil->handle($locked, $customer, ['paid_with' => $kinds]);

            $order->setRawAttributes($locked->refresh()->getAttributes(), true);

            return true;
        });

        if ($paid) {
            event(new OrderPaid($order));
        }

        return $order;
    }
}
