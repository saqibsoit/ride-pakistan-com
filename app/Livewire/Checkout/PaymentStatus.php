<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\GuestAccess;
use App\Support\Tracking\BrowserEvents;
use App\Support\Tracking\TrackingConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /checkout/{reference}/status (PLAN §3.1): polls every 3 seconds, for up
 * to 2 minutes, until the webhook has marked the order paid or the card
 * attempt failed. It only reads the order; it never fulfils.
 */
final class PaymentStatus extends Component
{
    public const POLL_SECONDS = 3;

    public const MAX_POLLS = 40;

    #[Locked]
    public string $reference = '';

    #[Locked]
    public int $polls = 0;

    /** The browser Purchase event went out (once per page visit). */
    #[Locked]
    public bool $purchaseTracked = false;

    public function mount(string $reference): void
    {
        $this->reference = $reference;
        $this->order(); // 404 for anyone else's order
    }

    public function poll(): void
    {
        if ($this->polls < self::MAX_POLLS) {
            $this->polls++;
        }
    }

    public function render(): View
    {
        $order = $this->order();

        $state = match (true) {
            in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true) => 'paid',
            $order->status === OrderStatus::PendingPayment && $order->stripe_checkout_session_id === null && (int) ($order->meta['stripe_attempts'] ?? 0) > 0 => 'failed',
            ! $order->status->isUnpaid() => 'closed',
            default => 'waiting',
        };

        // Browser Pixel/GA4 Purchase (fired by tracking.js only with
        // consent); same event id as the Meta CAPI event from the webhook.
        if ($state === 'paid' && ! $this->purchaseTracked && app(TrackingConfig::class)->anyEnabled()) {
            $this->purchaseTracked = true;
            $this->dispatch('rp-track', tracking: BrowserEvents::forOrder(BrowserEvents::PURCHASE, $order));
        }

        return view('livewire.checkout.payment-status', [
            'order' => $order,
            // Signed out, or someone else's account: point to the manage page, not "My account".
            'guest' => Auth::id() !== $order->user_id,
            'state' => $state,
            'polling' => $state === 'waiting' && $this->polls < self::MAX_POLLS,
            'timedOut' => $state === 'waiting' && $this->polls >= self::MAX_POLLS,
        ]);
    }

    /** The owner's order or one this guest may open (GuestAccess); a 404 for anyone else. */
    private function order(): Order
    {
        $user = Auth::user();
        $order = app(GuestAccess::class)->findOrder($user instanceof User ? $user : null, $this->reference);

        abort_if($order === null, 404);

        return $order->load('product');
    }
}
