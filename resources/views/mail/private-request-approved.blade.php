<x-mail::message>
# Your private session is approved

Good news: **{{ $request->label() }}** (Pakistan time) is yours if you pay by **{{ \App\Models\PrivateSessionRequest::localTime($request->pay_by) }}**.

@if ($order)
Amount: **{{ \App\Support\Money::format($order->amount_minor, $order->currency) }}** for {{ $request->riders }} {{ $request->riders === 1 ? 'rider' : 'riders' }} (reference {{ $order->reference }}).
@endif

Pay by card, or by bank transfer with the screenshot uploaded before the deadline. If it isn't paid in time, the slot is released.

<x-mail::button :url="$payUrl" color="primary">
Pay now
</x-mail::button>

Ride Pakistan
</x-mail::message>
