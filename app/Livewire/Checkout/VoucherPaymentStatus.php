<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Enums\VoucherOrderStatus;
use App\Models\User;
use App\Models\VoucherOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /gift-vouchers/{reference}/status (milestone 10e): polls like the order
 * status page until the webhook has issued the voucher or the card attempt
 * failed. Read-only.
 */
final class VoucherPaymentStatus extends Component
{
    #[Locked]
    public string $reference = '';

    #[Locked]
    public int $polls = 0;

    public function mount(string $reference): void
    {
        $this->reference = $reference;
        $this->order();
    }

    public function poll(): void
    {
        if ($this->polls < PaymentStatus::MAX_POLLS) {
            $this->polls++;
        }
    }

    public function render(): View
    {
        $order = $this->order();

        $state = match (true) {
            $order->status === VoucherOrderStatus::Paid => 'paid',
            $order->status === VoucherOrderStatus::PendingPayment && $order->stripe_checkout_session_id === null && (int) ($order->meta['stripe_attempts'] ?? 0) > 0 => 'failed',
            ! $order->status->isUnpaid() => 'closed',
            default => 'waiting',
        };

        return view('livewire.checkout.voucher-payment-status', [
            'order' => $order,
            'state' => $state,
            'polling' => $state === 'waiting' && $this->polls < PaymentStatus::MAX_POLLS,
            'timedOut' => $state === 'waiting' && $this->polls >= PaymentStatus::MAX_POLLS,
        ]);
    }

    private function order(): VoucherOrder
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->voucherOrders()->where('reference', $this->reference)->firstOrFail();
    }
}
