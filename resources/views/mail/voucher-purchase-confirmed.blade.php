<x-mail::message>
# Payment received

Thank you. Your gift voucher **{{ $order->reference }}** is paid and issued.

<x-mail::table>
| | |
|:--|:--|
| Voucher | {{ $order->label() }} |
| Amount paid | {{ \App\Support\Money::format($order->amount_minor, $order->currency) }} |
| For | {{ $voucher->recipient_name }} ({{ $voucher->recipient_email }}) |
| Sent | {{ $order->send_on ? $order->send_on->format('j F Y').', 9 am (Pakistan time)' : 'Now' }} |
| Valid until | {{ $voucher->expires_at->setTimezone(config('app.display_timezone'))->format('j F Y') }} |
</x-mail::table>

{{ $order->send_on ? 'We\'ll email the voucher to '.$voucher->recipient_name.' on the date you chose and let you know when it\'s gone.' : 'We\'ve emailed the voucher to '.$voucher->recipient_name.'.' }}

<x-mail::button :url="\App\Support\VoucherOrderLink::for($order)">
See your purchase
</x-mail::button>

No sign-in needed. Anyone with the link can see the purchase, so please don't share it.

{{ \App\Actions\Payments\CreateStripeCheckout::BRAND_LINE }}
</x-mail::message>
