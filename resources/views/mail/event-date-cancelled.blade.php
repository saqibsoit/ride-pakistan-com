<x-mail::message>
# We’ve had to cancel this date

We’re sorry: **{{ $order->productName() }}** on **{{ $slot->dateRangeLabel() }}** (Pakistan time) won’t go ahead.@if ($reason) {{ $reason }}@endif


Your booking **{{ $order->reference }}** is safe. Choose what suits you on your booking page:

- **A full refund** of everything you paid, deposit included.
- **Another date** of the same event, if one has room. Nothing more to pay than before.

@include('mail.partials.manage-link', ['for' => $order])

If you’d rather talk it through, just reply to this email.

Ride Pakistan
</x-mail::message>
