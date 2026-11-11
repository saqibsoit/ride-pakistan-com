<x-mail::message>
# See you {{ $slot->starts_at->setTimezone($timezone)->format('l') }}

Your session is on **{{ $slot->label() }}** (Pakistan time).

@if ($arrivalAt)
Please arrive by **{{ $arrivalAt->format('g:i a') }}** so there's time to meet the horses before you ride.
@endif

@if ($rules !== [] || $helmet !== '')
## Before you come

@if ($helmet !== '')
- **{{ $helmet }}**
@endif
@foreach ($rules as $rule)
- {{ $rule }}
@endforeach
@endif

## Your tickets

@foreach ($bookings as $booking)
**{{ $booking->rider->name }}**: ticket **{{ $booking->ticket_code }}**
@isset($message)
<br><img src="{{ $message->embedData($qr[$booking->id], 'ticket-'.$booking->ticket_code.'.png', 'image/png') }}" alt="QR code for ticket {{ $booking->ticket_code }}" width="160" height="160">
@endisset

@endforeach

@if ($mapPin !== '')
## Where to find us

<x-mail::button :url="$mapPin">
Open the map pin
</x-mail::button>
@endif

Can't make it? Credit bookings can be moved or cancelled from your account up to {{ $cutoffHours }} hours before the session.

<x-mail::button :url="route('account.bookings')" color="primary">
Your bookings
</x-mail::button>

Ride Pakistan
</x-mail::message>
