<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Support\Tracking\BrowserEvents;
use App\Support\Tracking\Consent;
use App\Support\Tracking\TrackingConfig;
use App\Support\Tracking\VisitorContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * First view of /checkout/{reference} for an unpaid order: queue the
 * browser InitiateCheckout event once, and, if the customer granted
 * tracking consent, keep what Meta CAPI needs to send Purchase later
 * from the webhook (`order.meta.tracking`). Without consent nothing
 * about the visitor is stored.
 */
final class RecordCheckoutTracking
{
    public function __construct(
        private readonly TrackingConfig $config,
        private readonly BrowserEvents $events,
    ) {}

    public function handle(Order $order, Request $request): void
    {
        if (! $this->config->anyEnabled() || ! $order->status->isUnpaid()) {
            return;
        }

        $tracking = $order->meta['tracking'] ?? [];

        if (is_array($tracking) && isset($tracking['checkout_at'])) {
            return;
        }

        $consented = Consent::granted($request);

        DB::transaction(function () use ($order, $request, $consented): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $meta = $locked->meta ?? [];

            $meta['tracking'] = [
                'checkout_at' => now()->toIso8601String(),
                'consent' => $consented,
                ...($consented ? VisitorContext::fromRequest($request, $request->fullUrl())->toArray() : []),
            ];

            $locked->meta = $meta;
            $locked->save();
            $order->meta = $meta;
        });

        $this->events->push(BrowserEvents::forOrder(BrowserEvents::INITIATE_CHECKOUT, $order));
    }
}
