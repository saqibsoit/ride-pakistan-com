<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use LogicException;

/**
 * Books money that went back to the customer: a refunded payment row, the
 * order's refunded total and, for a paid order, the move to partially
 * refunded or refunded (OrderStateMachine, reason required). It changes
 * no bookings or credits; RefundOrder does that for admin refunds.
 *
 * The caller holds the order row lock inside its transaction.
 */
final class RecordRefund
{
    public function __construct(private readonly OrderStateMachine $orders) {}

    /**
     * @param  array<string, mixed>  $raw  trimmed provider payload
     * @param  array<string, scalar|null>  $context  audit details
     */
    public function handle(Order $order, int $amountMinor, ?string $providerId, string $reason, ?User $actor = null, array $raw = [], array $context = []): Payment
    {
        if ($amountMinor <= 0) {
            throw new LogicException('A refund must be for a positive amount.');
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'provider' => PaymentProvider::Stripe,
            'provider_id' => $providerId,
            'amount_minor' => $amountMinor,
            'currency' => $order->currency,
            'status' => PaymentStatus::Refunded,
            'raw' => $raw,
        ]);

        $order->amount_refunded_minor += $amountMinor;
        $order->save();

        if (in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
            $to = $order->amount_refunded_minor >= $order->amount_minor ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded;

            $this->orders->transition($order, $to, $actor, $reason, [...$context, 'refund_minor' => $amountMinor, 'refunded_total_minor' => $order->amount_refunded_minor]);
        }

        return $payment;
    }
}
