<x-mail::message>
# We've received your transfer

Thank you. We've received the proof of your bank transfer for order **{{ $order->reference }}** ({{ \App\Support\Money::format($order->amount_minor, $order->currency) }}).

We'll check it against our account and confirm shortly. Your {{ $order->holds()->exists() ? 'seats stay held' : 'order stays open' }} until then, and you'll get another email once your booking is confirmed.

<x-mail::button :url="route('account.orders.show', $order->reference)">
See your order
</x-mail::button>

Ride Pakistan
</x-mail::message>
