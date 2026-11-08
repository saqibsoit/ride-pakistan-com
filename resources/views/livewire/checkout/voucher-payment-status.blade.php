<div @if ($polling) wire:poll.{{ \App\Livewire\Checkout\PaymentStatus::POLL_SECONDS }}s="poll" @endif aria-live="polite" class="flex flex-col gap-6">
    @if ($state === 'paid')
        <x-alert variant="success" title="Payment received. Your voucher is issued.">
            {{ $order->send_on ? 'We\'ll email it to '.$order->recipient_name.' on '.$order->send_on->format('j F Y').' at 9 am (Pakistan time).' : 'We\'ve emailed it to '.$order->recipient_name.'.' }} You'll get a copy of the receipt.
        </x-alert>
        <div><x-button :href="route('account.vouchers')">Your gift vouchers</x-button></div>
    @elseif ($state === 'failed')
        <x-alert variant="warning" title="Your card payment didn't go through.">
            Nothing was charged. Try another card, or pay by bank transfer with reference <strong>{{ $order->reference }}</strong>.
        </x-alert>
        <div><x-button :href="route('gift-vouchers.show', $order->reference)">Choose how to pay</x-button></div>
    @elseif ($state === 'closed')
        <x-alert variant="warning" title="This purchase is {{ strtolower($order->status->label()) }}.">
            It can't be paid any more. <a href="{{ route('gift-vouchers.create') }}" class="underline underline-offset-4">Start again</a>.
        </x-alert>
    @elseif ($timedOut)
        <x-alert variant="info" title="We're still confirming your payment.">
            This can take a few minutes. You don't need to pay again: we'll email you as soon as it's confirmed.
        </x-alert>
        <div><x-button variant="ghost" :href="route('account.vouchers')">Your gift vouchers</x-button></div>
    @else
        <x-alert variant="info" title="Confirming your payment…">
            This usually takes a few seconds. Please keep this page open.
        </x-alert>
    @endif
</div>
