<x-mail::message>
# Private session confirmed

You're running a private session on **{{ $request->label() }}** (Pakistan time) for {{ $request->riders }} {{ $request->riders === 1 ? 'rider' : 'riders' }}. It's paid and confirmed.

<x-mail::button :url="$runSheet" color="primary">
Open the run sheet
</x-mail::button>

Ride Pakistan
</x-mail::message>
