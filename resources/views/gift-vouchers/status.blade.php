{{-- /gift-vouchers/{reference}/status: Stripe's return page for a gift voucher. Shows what the webhook recorded; never issues. --}}
<x-layouts.app title="Payment {{ $order->reference }}" robots="noindex, nofollow" livewire>
    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="status-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Gift voucher {{ $order->reference }}</p>
        <h1 id="status-title" class="mt-3 text-display-xl">Payment status.</h1>

        <div class="mt-10 flex flex-col gap-6">
            @if ($cancelled && $order->status === \App\Enums\VoucherOrderStatus::PendingPayment)
                <x-alert variant="info" title="You left the card page.">Nothing was charged. You can go back and pay by card or bank transfer.</x-alert>
                <x-button :href="route('gift-vouchers.show', $order->reference)">Choose how to pay</x-button>
            @else
                <livewire:checkout.voucher-payment-status :reference="$order->reference" />
            @endif
        </div>
    </section>
</x-layouts.app>
