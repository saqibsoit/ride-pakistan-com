<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
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

        return view('livewire.checkout.payment-status', [
            'order' => $order,
            'state' => $state,
            'polling' => $state === 'waiting' && $this->polls < self::MAX_POLLS,
            'timedOut' => $state === 'waiting' && $this->polls >= self::MAX_POLLS,
        ]);
    }

    private function order(): Order
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->orders()->where('reference', $this->reference)->with('product')->firstOrFail();
    }
}
