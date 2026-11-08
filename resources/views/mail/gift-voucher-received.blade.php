<x-mail::message>
# {{ $voucher->recipient_name ? 'Hi '.$voucher->recipient_name.', you' : 'You' }}'ve got a gift voucher

{{ $from ? $from.' has' : 'Someone has' }} given you **{{ \App\Support\Money::format($voucher->initial_minor, $voucher->currency) }}**{{ $voucher->label ? ' ('.$voucher->label.')' : '' }} to spend on horse riding with Ride Pakistan in Islamabad.

@if ($message)
<x-mail::panel>
{{ $message }}
</x-mail::panel>
@endif

<x-mail::table>
| | |
|:--|:--|
| Your code | **{{ $voucher->formattedCode() }}** |
| Value | {{ \App\Support\Money::format($voucher->initial_minor, $voucher->currency) }} |
| Valid until | {{ $voucher->expires_at->setTimezone(config('app.display_timezone'))->format('j F Y') }} |
</x-mail::table>

Use the code when you book any session or package. If the booking costs less, the rest stays on the voucher for next time.

<x-mail::button :url="route('prices')">
See sessions and prices
</x-mail::button>

[Open your printable voucher]({{ $printUrl }})

Keep this email: the code is all you need. Vouchers can't be exchanged for cash.

Ride Pakistan
</x-mail::message>
