<x-mail::message>
# Your booking is cancelled

Booking **{{ $order->reference }}** for **{{ $order->productName() }}** is cancelled{{ $byUs ? ', as you chose after we cancelled the date' : '' }}.

@if ($refund > 0)
We’re refunding **{{ \App\Support\Money::format($refund, $order->currency) }}**.
@if ($manual)
Part of it was paid by bank transfer or at the venue, so we’ll send it back by bank transfer. We’ll be in touch on WhatsApp for your account details.
@else
Card refunds usually reach your account within 5 to 10 working days.
@endif
@else
@if ($order->deposit_percent !== null)
As set out when you booked, the deposit is non-refundable, so there is no refund.
@else
As set out when you booked, there is no refund for cancellations this close to the date.
@endif
@endif

Any balance you hadn't paid yet is no longer owed.

Ride Pakistan
</x-mail::message>
