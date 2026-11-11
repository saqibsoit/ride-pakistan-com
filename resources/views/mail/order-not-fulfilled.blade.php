<x-mail::message>
# We couldn't confirm your booking

We received your payment for order **{{ $order->reference }}**, but by the time it arrived the session was no longer available (it was closed, cancelled or fully booked), so no booking was made.

@if ($refunded)
**We've refunded the full amount to your card.** How soon it shows on your statement depends on your bank.
@else
**Your money is safe.** We'll be in touch to move you to another session or refund you, whichever you prefer.
@endif

If you have any questions, email us at {{ app(\App\Support\Settings::class)->string(\App\Enums\SettingKey::Email) }} with your reference **{{ $order->reference }}**.

<x-mail::button :url="route('schedule')">
See other sessions
</x-mail::button>

Ride Pakistan
</x-mail::message>
