<x-mail::message>
# Pay by bank transfer

Your booking **{{ $order->reference }}** is held while you make the transfer. Please send **{{ \App\Support\Money::format($order->amount_minor, $order->currency) }}** with the reference **{{ $order->reference }}**@if ($order->expires_at) and upload the screenshot before **{{ $order->expires_at->setTimezone($timezone)->format('D j M, H:i') }}** (Pakistan time)@endif.

<x-mail::table>
| | |
|:--|:--|
@foreach ($bank as $label => $value)
@if (filled($value))
| {{ $label }} | {{ $value }} |
@endif
@endforeach
| Reference | **{{ $order->reference }}** |
</x-mail::table>

When you've made it, open your booking to upload the screenshot:

<x-mail::button :url="$url">
Manage your booking
</x-mail::button>

Anyone with this link can see the booking, so please don't share it. We confirm your booking once we've checked the transfer.

Ride Pakistan
</x-mail::message>
