<x-mail::message>
# Gift voucher transfer to verify

A customer has uploaded proof of a bank transfer for a gift voucher.

<x-mail::table>
| | |
|:--|:--|
| Reference | {{ $order->reference }} |
| Expected | {{ \App\Support\Money::format($order->amount_minor, $order->currency) }} |
| Voucher | {{ $order->label() }} |
| Customer | {{ $order->buyerName() ?? 'Customer' }}{{ $order->placed_as_guest ? ' (guest)' : '' }} |
</x-mail::table>

Check that the amount arrived with the reference **{{ $order->reference }}**, then approve or reject it. The voucher is issued only once you approve.

<x-mail::button :url="$queueUrl">
Open the voucher transfer queue
</x-mail::button>

Ride Pakistan
</x-mail::message>
