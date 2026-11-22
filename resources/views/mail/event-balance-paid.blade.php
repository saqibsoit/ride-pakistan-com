<x-mail::message>
# Balance received

Thank you. We've received your balance of **{{ \App\Support\Money::format($balance->amount_minor, $balance->currency) }}** for booking **{{ $order->reference }}**{{ $balance->method ? ' ('.$balance->method->getLabel().')' : '' }}. You're all paid up.

@if ($event)
@include('mail.partials.event-booking', ['event' => $event])
@endif

@include('mail.partials.manage-link', ['for' => $order])

Ride Pakistan
</x-mail::message>
