<x-mail::message>
# You're booked

@if ($viaTransfer)
Your bank transfer for order **{{ $order->reference }}** has been checked and approved. Thank you.
@else
Thank you. We've received your payment for order **{{ $order->reference }}**.
@endif

<x-mail::table>
| | |
|:--|--:|
| {{ $order->productName() }} | {{ \App\Support\Money::format($order->amount_minor, $order->currency) }} |
@if ($order->paid_at)
| Paid (Pakistan time) | {{ $order->paid_at->setTimezone($timezone)->format('j M Y, H:i') }} |
@endif
</x-mail::table>

@if ($bookings->isNotEmpty())
## Your tickets

Show the code at the gate. Staff scan it to check you in.

@foreach ($bookings as $booking)
**{{ $booking->rider->name }}**: {{ $booking->slot->label() }} (Pakistan time)<br>
Ticket **{{ $booking->ticket_code }}**
@isset($message)
<br><img src="{{ $message->embedData($qr[$booking->id], 'ticket-'.$booking->ticket_code.'.png', 'image/png') }}" alt="QR code for ticket {{ $booking->ticket_code }}" width="180" height="180">
@endisset

@endforeach
The ticket images are also attached, and each ticket is in your account.
@endif

@if ($order->creditPack)
**Your package:** {{ $order->creditPack->creditsRemaining() }} {{ $order->creditPack->creditsRemaining() === 1 ? 'credit' : 'credits' }} left, valid until {{ $order->creditPack->expires_at->setTimezone($timezone)->format('j M Y') }}. Book sessions from your account.
@endif

@if ($mapPin !== '' && $bookings->isNotEmpty())
## Where to find us

Here's the exact location for your session in Islamabad (please don't share it publicly):

<x-mail::button :url="$mapPin">
Open the map pin
</x-mail::button>
@endif

<x-mail::button :url="route('account.orders.show', $order->reference)" color="primary">
See your order
</x-mail::button>

The charge comes from {{ $brand }}.

Ride Pakistan
</x-mail::message>
