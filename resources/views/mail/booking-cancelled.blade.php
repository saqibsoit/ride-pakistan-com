<x-mail::message>
# Booking cancelled

The booking for **{{ $booking->rider->name }}** on {{ $booking->slot->label() }} (Pakistan time) is cancelled. Ticket {{ $booking->ticket_code }} no longer works at the gate.

@if ($usedCredit)
@if ($creditReturned)
The credit is back in your package, ready for another session.
@else
The credit for this session has been used, because it was cancelled after the cutoff.
@endif
@endif

The attached .ics file removes the session from Apple Calendar or Outlook. If you added it to Google Calendar, please delete it there.

<x-mail::button :url="route('schedule')" color="primary">
Book another session
</x-mail::button>

@include('mail.partials.manage-link', ['for' => $booking])

If this wasn't expected, reply to this email or [contact us]({{ route('contact') }}).

Ride Pakistan
</x-mail::message>
