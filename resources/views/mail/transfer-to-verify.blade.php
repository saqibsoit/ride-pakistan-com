<x-mail::message>
# Transfer to verify

A customer has uploaded proof of a bank transfer.

<x-mail::table>
| | |
|:--|:--|
| Order | {{ $order->reference }} |
| Expected | {{ \App\Support\Money::format($order->amount_minor, $order->currency) }} |
| Product | {{ $order->productName() }} |
| Customer | {{ $order->user->name ?? 'Customer' }} |
</x-mail::table>

Check that the amount arrived with the reference **{{ $order->reference }}**, then approve or reject it.

<x-mail::button :url="$queueUrl">
Open the verification queue
</x-mail::button>

Ride Pakistan
</x-mail::message>
