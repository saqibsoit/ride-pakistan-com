<?php

declare(strict_types=1);

namespace App\Support\Tracking;

use App\Models\Order;

/**
 * Conversion events for the browser Pixel / GA4, rendered into the page's
 * tracking config and fired by resources/js/tracking.js only after
 * consent. Events raised before a redirect are flashed to the session.
 * The event id is shared with Meta CAPI so Meta counts the event once.
 */
final class BrowserEvents
{
    public const SESSION_KEY = 'tracking_events';

    public const LEAD = 'Lead';

    public const INITIATE_CHECKOUT = 'InitiateCheckout';

    public const PURCHASE = 'Purchase';

    /** Events pushed for the current response live on the request itself. */
    private const REQUEST_KEY = 'tracking.browser_events';

    public function __construct(private readonly TrackingConfig $config) {}

    public static function checkoutEventId(Order $order): string
    {
        return 'checkout-'.$order->reference;
    }

    public static function purchaseEventId(Order $order): string
    {
        return 'purchase-'.$order->reference;
    }

    /**
     * @return array{name: string, id: string, value?: float, currency?: string, reference?: string}
     */
    public static function forOrder(string $name, Order $order): array
    {
        return [
            'name' => $name,
            'id' => $name === self::PURCHASE ? self::purchaseEventId($order) : self::checkoutEventId($order),
            // Major PKR units for the vendors; money stays minor everywhere else.
            'value' => intdiv($order->amount_minor, 100) + ($order->amount_minor % 100) / 100,
            'currency' => $order->currency,
            'reference' => $order->reference,
        ];
    }

    /**
     * @param  array{name: string, id: string, value?: float, currency?: string, reference?: string}  $event
     */
    public function push(array $event): void
    {
        if ($this->config->anyEnabled()) {
            $attributes = request()->attributes;
            $attributes->set(self::REQUEST_KEY, [...$this->pushed(), $event]);
        }
    }

    /**
     * @param  array{name: string, id: string, value?: float, currency?: string, reference?: string}  $event
     */
    public function flash(array $event): void
    {
        if (! $this->config->anyEnabled()) {
            return;
        }

        /** @var list<array<string, mixed>> $flashed */
        $flashed = session()->get(self::SESSION_KEY, []);
        session()->flash(self::SESSION_KEY, [...$flashed, $event]);
    }

    /**
     * Pushed this request plus flashed by the previous one.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $flashed = session()->get(self::SESSION_KEY, []);

        return [...(is_array($flashed) ? array_values($flashed) : []), ...$this->pushed()];
    }

    /** @return list<array<string, mixed>> */
    private function pushed(): array
    {
        $pushed = request()->attributes->get(self::REQUEST_KEY, []);

        return is_array($pushed) ? array_values($pushed) : [];
    }
}
