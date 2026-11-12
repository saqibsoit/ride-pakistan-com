<x-mail::message>
# We couldn't confirm your transfer

We couldn't match your bank transfer for order **{{ $order->reference }}**, so the booking was not confirmed and the seats have been released.

**Reason:** {{ $reason }}

If you think this is a mistake, or the money has left your account, email us at {{ app(\App\Support\Settings::class)->string(\App\Enums\SettingKey::Email) }} with your reference **{{ $order->reference }}** and we'll sort it out.

<x-mail::button :url="route('book')">
Book again
</x-mail::button>

@include('mail.partials.manage-link', ['for' => $order])

Ride Pakistan
</x-mail::message>
