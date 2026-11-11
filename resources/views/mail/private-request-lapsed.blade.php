<x-mail::message>
# Your private session wasn't booked

@if ($rejected)
We couldn't confirm the bank transfer for **{{ $request->label() }}**, so the slot has been released.
@else
The payment for **{{ $request->label() }}** didn't arrive in time, so the slot has been released. Nothing was charged.
@endif

You're welcome to send a new request.

<x-mail::button :url="$requestUrl" color="primary">
Request again
</x-mail::button>

Ride Pakistan
</x-mail::message>
