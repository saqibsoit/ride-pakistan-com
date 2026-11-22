<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\BalanceStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderBalance;
use App\Models\Payment;
use App\Models\User;

/**
 * Writes a refund of (part of) a paid event balance: a refunded payments
 * row (purpose balance) and the balance's refunded total. Runs inside the
 * caller's transaction with the balance and order locked.
 */
final class RecordBalanceRefund
{
    public static function apply(OrderBalance $balance, Order $order, int $amountMinor, ?string $providerId, PaymentProvider $provider, string $reason, ?User $actor): void
    {
        $amountMinor = min($amountMinor, $balance->receivedMinor());

        if ($amountMinor <= 0) {
            return;
        }

        Payment::query()->create([
            'order_id' => $order->id,
            'purpose' => PaymentPurpose::Balance,
            'provider' => $provider,
            'provider_id' => $providerId,
            'amount_minor' => $amountMinor,
            'currency' => $balance->currency,
            'status' => PaymentStatus::Refunded,
            'raw' => ['reason' => $reason],
        ]);

        $balance->refunded_minor += $amountMinor;

        if ($balance->refunded_minor >= $balance->amount_minor) {
            $balance->status = BalanceStatus::Refunded;
        }

        $balance->save();

        activity('payments')
            ->performedOn($order)
            ->causedBy($actor)
            ->event('balance_refunded')
            ->withProperties(['amount_minor' => $amountMinor, 'provider' => $provider->value, 'reason' => $reason])
            ->log('Balance refund of '.$amountMinor.' recorded for order '.$order->reference);
    }
}
