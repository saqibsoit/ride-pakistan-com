<x-mail::message>
# Your gift voucher has been sent

We've just emailed your {{ \App\Support\Money::format($voucher->initial_minor, $voucher->currency) }} gift voucher to {{ $voucher->recipient_name }} ({{ $voucher->recipient_email }}).

<x-mail::button :url="route('account.vouchers')">
See your gift vouchers
</x-mail::button>

Ride Pakistan
</x-mail::message>
