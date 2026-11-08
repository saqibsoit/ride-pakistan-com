<x-mail::message>
# We couldn't confirm your transfer

We couldn't match your bank transfer for gift voucher **{{ $order->reference }}**, so no voucher was issued.

**Reason:** {{ $reason }}

If you think this is a mistake, or the money has left your account, email us at {{ app(\App\Support\Settings::class)->string(\App\Enums\SettingKey::Email) }} with your reference **{{ $order->reference }}** and we'll sort it out.

<x-mail::button :url="route('gift-vouchers')">
Gift vouchers
</x-mail::button>

Ride Pakistan
</x-mail::message>
