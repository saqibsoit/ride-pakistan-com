<x-mail::message>
# We can't do that time

We're sorry: we can't run a private session on **{{ $request->label() }}**.

**Why:** {{ $request->decline_reason }}

@if ($request->suggested_times)
**Times that could work:** {{ $request->suggested_times }}
@endif

<x-mail::button :url="$requestUrl" color="primary">
Request another time
</x-mail::button>

Ride Pakistan
</x-mail::message>
