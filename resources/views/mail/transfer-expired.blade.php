<x-mail::message>
# Your booking has expired

We didn't receive proof of a bank transfer for order **{{ $order->reference }}** before the deadline, so the seats have been released.

If you've already transferred the money, email us at {{ app(\App\Support\Settings::class)->string(\App\Enums\SettingKey::Email) }} with your reference **{{ $order->reference }}** and a screenshot, and we'll sort it out.

<x-mail::button :url="route('book')">
Book again
</x-mail::button>

@include('mail.partials.manage-link', ['for' => $order])

Ride Pakistan
</x-mail::message>
