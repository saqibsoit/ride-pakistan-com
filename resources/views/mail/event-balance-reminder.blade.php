<x-mail::message>
# Your balance is due soon

@if ($event)
The balance for booking **{{ $order->reference }}** is **{{ \App\Support\Money::format($event->balanceMinor(), $order->currency) }}**, {{ $event->balanceDueText() }}.

@if ($event->canPayBalanceOnline())
You can pay it online now from your booking page (card), so there's nothing to settle at the venue.

@endif
@include('mail.partials.event-booking', ['event' => $event])
@endif

@include('mail.partials.manage-link', ['for' => $order])

Ride Pakistan
</x-mail::message>
