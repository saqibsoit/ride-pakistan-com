<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Raises a payment alert (PLAN §7): critical log entry (picked up by error
 * monitoring), an audit-log entry and a "needs review" flag in the order's
 * meta that the admin Orders list shows and filters on.
 */
final class FlagOrderForReview
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function handle(Order $order, string $event, string $reason, array $context = []): void
    {
        $meta = $order->meta ?? [];
        $meta['needs_review'] = $reason;
        $order->meta = $meta;
        $order->save();

        Log::critical('Payment alert on order '.$order->reference.': '.$reason, [...$context, 'order_id' => $order->id, 'event' => $event]);

        activity('payments')
            ->performedOn($order)
            ->event($event)
            ->withProperties([...$context, 'reason' => $reason])
            ->log('Order '.$order->reference.' needs review: '.$reason);
    }

    public function clear(Order $order): void
    {
        $meta = $order->meta ?? [];
        unset($meta['needs_review']);
        $order->meta = $meta;
        $order->save();
    }
}
