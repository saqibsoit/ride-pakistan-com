<x-mail::message>
# Your gift voucher purchase has expired

We didn't receive proof of a bank transfer for gift voucher **{{ $order->reference }}** before the deadline, so the purchase was closed and no voucher was issued.

If you've already transferred the money, email us at {{ app(\App\Support\Settings::class)->string(\App\Enums\SettingKey::Email) }} with your reference **{{ $order->reference }}** and a screenshot, and we'll sort it out.

<x-mail::button :url="route('gift-vouchers')">
Gift vouchers
</x-mail::button>

Ride Pakistan
</x-mail::message>
