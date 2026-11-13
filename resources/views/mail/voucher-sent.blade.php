<x-mail::message>
# Your gift voucher has been sent

We've just emailed your {{ \App\Support\Money::format($voucher->initial_minor, $voucher->currency) }} gift voucher to {{ $voucher->recipient_name }} ({{ $voucher->recipient_email }}).

@if ($voucher->voucherOrder)
<x-mail::button :url="\App\Support\VoucherOrderLink::for($voucher->voucherOrder)">
See your purchase
</x-mail::button>
@endif

Ride Pakistan
</x-mail::message>
