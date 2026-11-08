<x-mail::message>
# Payment received

Thank you. We've received your payment for order **{{ $order->reference }}**.

<x-mail::table>
| | |
|:--|--:|
| {{ $order->product->name }} | {{ \App\Support\Money::format($order->amount_minor, $order->currency) }} |
@if ($order->paid_at)
| Paid (Pakistan time) | {{ $order->paid_at->setTimezone($timezone)->format('j M Y, H:i') }} |
@endif
</x-mail::table>

@if ($order->bookings->isNotEmpty())
**Your sessions**

@foreach ($order->bookings as $booking)
- {{ $booking->slot->label() }} (Pakistan time): {{ $booking->rider->name }}, ticket {{ $booking->ticket_code }}
@endforeach
@endif

@if ($order->creditPack)
**Your package:** {{ $order->creditPack->creditsRemaining() }} {{ $order->creditPack->creditsRemaining() === 1 ? 'credit' : 'credits' }} left, valid until {{ $order->creditPack->expires_at->setTimezone($timezone)->format('j M Y') }}.
@endif

<x-mail::button :url="route('account.orders.show', $order->reference)">
See your order
</x-mail::button>

The charge comes from {{ $brand }}.

Ride Pakistan
</x-mail::message>
