<x-mail::message>
# Your session has a new time

We've had to change the time of your session.

**Was:** {{ $notice->oldLabel() }}<br>
**Now:** {{ $notice->newLabel() }} (Pakistan time)

@foreach ($bookings as $booking)
**{{ $booking->rider->name }}**: ticket **{{ $booking->ticket_code }}** (same ticket as before) · [Update Google Calendar]({{ $calendar[$booking->id] }})

@endforeach
The attached .ics file updates the entry in Apple Calendar or Outlook.

**What would you like to do?** You can choose until the session starts. If you don't choose, your booking stays at the new time.

<x-mail::button :url="$acceptUrl" color="primary">
Keep the new time
</x-mail::button>

<x-mail::button :url="$moveUrl">
Move to another session
</x-mail::button>

<x-mail::button :url="$cancelUrl">
Cancel
</x-mail::button>

@if ($paid)
If you cancel a session you paid for, we'll be in touch about your refund.
@else
If you cancel, the credit goes back to your package.
@endif

@include('mail.partials.manage-link', ['for' => $bookings])

Ride Pakistan
</x-mail::message>
