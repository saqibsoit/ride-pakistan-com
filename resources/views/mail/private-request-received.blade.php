<x-mail::message>
# We've got your request

Thanks. You've asked for a private session on **{{ $request->label() }}** (Pakistan time) for {{ $request->riders }} {{ $request->riders === 1 ? 'rider' : 'riders' }}.

We'll answer by **{{ \App\Models\PrivateSessionRequest::localTime($request->answer_by) }}**. If we approve it, you'll have {{ app(\App\Actions\PrivateRequests\PrivateRequestRules::class)->payHours() }} hours to pay, and the session is yours. Nothing is booked or charged until then.

Reference: **{{ $request->reference }}**

<x-mail::button :url="$account" color="primary">
Follow your request
</x-mail::button>

No sign-in needed. Anyone with the link can see the request, so please don't share it.

Ride Pakistan
</x-mail::message>
