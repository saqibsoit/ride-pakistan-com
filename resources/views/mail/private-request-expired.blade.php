<x-mail::message>
# Your request has expired

We're sorry: we couldn't answer your private session request for **{{ $request->label() }}** in time, so it has expired. Nothing was booked or charged.

You're welcome to send a new request, or message us.

<x-mail::button :url="$requestUrl" color="primary">
Request again
</x-mail::button>

[Contact us]({{ $contactUrl }})

Ride Pakistan
</x-mail::message>
