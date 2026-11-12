<x-mail::message>
# Private session request

**{{ $request->label() }}** (Pakistan time), {{ $request->riders }} {{ $request->riders === 1 ? 'rider' : 'riders' }}.

- Customer: {{ $request->user->displayName() }} ({{ $request->user->email }})
- WhatsApp: {{ $request->user->whatsapp_phone ?? 'not given' }}
- Reference: {{ $request->reference }}
@if ($request->note)
- Note: {{ $request->note }}
@endif

**Answer by {{ \App\Models\PrivateSessionRequest::localTime($request->answer_by) }}**, or the request expires and the customer is told.

<x-mail::button :url="$approveUrl" color="primary">
Approve
</x-mail::button>

<x-mail::button :url="$declineUrl">
Decline
</x-mail::button>

Both buttons open the request in the admin panel (sign-in and MFA as usual), where you confirm. Approving creates a private slot and gives the customer the payment link. [Open the request]({{ $viewUrl }})

Ride Pakistan
</x-mail::message>
