<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Models\Order;
use App\Models\OrderBalance;
use App\Models\RideEvent;
use App\Models\Slot;

/**
 * Called by FulfilOrder only, inside its transaction: a deposit event order
 * (balance_minor > 0) gets its balance row, due on the day or by the
 * event's online deadline (decided 2026-11-22). Idempotent per order.
 */
final class OpenOrderBalance
{
    public function open(Order $order, ?int $slotId): ?OrderBalance
    {
        if (! $order->isEvent() || $order->balance_minor <= 0 || $slotId === null) {
            return null;
        }

        $existing = OrderBalance::query()->where('order_id', $order->id)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        $event = RideEvent::query()->findOrFail($order->ride_event_id);
        $occurrence = Slot::query()->findOrFail($slotId);
        $onlineUntil = $event->balanceOnlineUntil($occurrence);

        return OrderBalance::query()->create([
            'order_id' => $order->id,
            'amount_minor' => $order->balance_minor,
            'currency' => $order->currency,
            'due_at' => $onlineUntil,
            'online_until' => $onlineUntil,
        ]);
    }
}
