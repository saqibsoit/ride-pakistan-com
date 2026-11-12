<x-mail::message>
# Your link to manage your booking

@if ($revoked)
The earlier links for order **{{ $order->reference }}** have been stopped. Anyone who had one can no longer open your booking. Here is your new link.
@else
Here is the link to manage order **{{ $order->reference }}**, as you asked.
@endif

<x-mail::button :url="$url">
Manage your booking
</x-mail::button>

Anyone with this link can see the booking, so please don't share it. It works until 7 days after your last session. If you didn't ask for this, you can ignore this email.

Ride Pakistan
</x-mail::message>
