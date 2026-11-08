<div @if ($polling) wire:poll.{{ \App\Livewire\Checkout\PaymentStatus::POLL_SECONDS }}s="poll" @endif aria-live="polite" class="flex flex-col gap-6">
    @if ($state === 'paid')
        <x-alert variant="success" title="Payment received. You're booked.">
            We've emailed your receipt. Your sessions and tickets are in your account.
        </x-alert>
        <div class="flex flex-wrap gap-3">
            <x-button :href="route('account.orders.show', $order->reference)">See your order</x-button>
            <x-button variant="ghost" :href="route('account.bookings')">Your bookings</x-button>
        </div>
    @elseif ($state === 'failed')
        <x-alert variant="warning" title="Your card payment didn't go through.">
            Nothing was charged. Your seats are still held for a while: try another card, or pay by bank transfer with reference <strong>{{ $order->reference }}</strong>.
        </x-alert>
        <x-button :href="route('checkout.show', $order->reference)">Choose how to pay</x-button>
    @elseif ($state === 'closed')
        <x-alert variant="warning" title="This order is {{ strtolower($order->status->label()) }}.">
            It can't be paid any more. <a href="{{ route('book') }}" class="underline underline-offset-4">Book again</a>.
        </x-alert>
    @elseif ($timedOut)
        <x-alert variant="info" title="We're still confirming your payment.">
            This can take a few minutes. You don't need to pay again: we'll email you as soon as it's confirmed, and the order in your account will update.
        </x-alert>
        <x-button variant="ghost" :href="route('account.orders.show', $order->reference)">See your order</x-button>
    @else
        <x-alert variant="info" title="Confirming your payment…">
            This usually takes a few seconds. Please keep this page open.
        </x-alert>
        <x-button variant="ghost" :href="route('checkout.show', $order->reference)">Back to checkout</x-button>
    @endif
</div>
