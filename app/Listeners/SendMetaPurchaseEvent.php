<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Support\Tracking\BrowserEvents;
use App\Support\Tracking\MetaConversions;
use App\Support\Tracking\MetaEvent;
use App\Support\Tracking\VisitorContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Server-side Meta "Purchase" for a paid order (card webhook or approved
 * transfer), only if the customer granted tracking consent at checkout
 * (RecordCheckoutTracking kept their context in `meta.tracking`). Sent
 * once per order; same event id as the browser Pixel's Purchase.
 */
final class SendMetaPurchaseEvent implements ShouldQueue
{
    public function __construct(private readonly MetaConversions $meta) {}

    public function shouldQueue(OrderPaid $event): bool
    {
        return $this->meta->enabled() && self::consentedContext($event->order) !== null;
    }

    public function handle(OrderPaid $event): void
    {
        // Claim the send first so a retried job or a second OrderPaid can't
        // report the purchase twice.
        $order = DB::transaction(function () use ($event): ?Order {
            $locked = Order::query()->whereKey($event->order->getKey())->lockForUpdate()->first();

            if ($locked === null || self::consentedContext($locked) === null || isset($locked->meta['tracking']['capi_purchase_sent_at'])) {
                return null;
            }

            $meta = $locked->meta ?? [];
            $meta['tracking']['capi_purchase_sent_at'] = now()->toIso8601String();
            $locked->meta = $meta;
            $locked->save();

            return $locked;
        });

        $visitor = $order === null ? null : self::consentedContext($order);

        if ($order === null || $visitor === null) {
            return;
        }

        $order->loadMissing('user');

        $this->meta->send(new MetaEvent(
            name: BrowserEvents::PURCHASE,
            eventId: BrowserEvents::purchaseEventId($order),
            time: $order->paid_at ?? now()->toImmutable(),
            visitor: $visitor,
            email: $order->user->email,
            phone: $order->user->phone,
            valueMinor: $order->amount_minor,
            currency: $order->currency,
            orderId: $order->reference,
        ));
    }

    private static function consentedContext(Order $order): ?VisitorContext
    {
        $tracking = $order->meta['tracking'] ?? null;

        if (! is_array($tracking) || ($tracking['consent'] ?? false) !== true) {
            return null;
        }

        /** @var array<string, mixed> $tracking */
        return VisitorContext::fromArray($tracking);
    }
}
