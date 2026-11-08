<x-mail::message>
# We've received your transfer

Thank you. We've received the proof of your bank transfer for gift voucher **{{ $order->reference }}** ({{ \App\Support\Money::format($order->amount_minor, $order->currency) }}).

We'll check it against our account shortly. As soon as it's confirmed we'll {{ $order->send_on ? 'send the voucher to '.$order->recipient_name.' on the date you chose' : 'email the voucher to '.$order->recipient_name }}, and let you know.

<x-mail::button :url="route('account.vouchers')">
See your gift vouchers
</x-mail::button>

Ride Pakistan
</x-mail::message>
