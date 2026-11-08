<x-mail::message>
# Your booking has moved

@php($first = $bookings->first())
We've moved your booking from {{ $from->label() }} to **{{ $first->slot->label() }}** (Pakistan time).

@foreach ($bookings as $booking)
**{{ $booking->rider->name }}**: ticket **{{ $booking->ticket_code }}** (same ticket as before) · [Update Google Calendar]({{ $calendar[$booking->id] }})

@endforeach
The attached .ics file updates the entry in Apple Calendar or Outlook. In Google Calendar, remove the old entry and add the new one with the link above.

@if ($mapPin !== '')
<x-mail::button :url="$mapPin">
Open the map pin
</x-mail::button>
@endif

<x-mail::button :url="route('account.bookings')" color="primary">
See your bookings
</x-mail::button>

Ride Pakistan
</x-mail::message>
