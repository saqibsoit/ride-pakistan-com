<x-mail::message>
# See you {{ $slot->starts_at->setTimezone($timezone)->format('l') }}

@if ($event)
**{{ $event->event->title }}** is on **{{ $slot->dateRangeLabel() }}** (Pakistan time).

@include('mail.partials.event-booking', ['event' => $event])
@else
Your session is on **{{ $slot->label() }}** (Pakistan time).
@endif

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
Get directions
</x-mail::button>
@endif

@unless ($event)
Can't make it? Credit bookings can be moved or cancelled up to {{ $cutoffHours }} hours before the session.
@endunless

@include('mail.partials.manage-link', ['for' => $bookings])

Ride Pakistan
</x-mail::message>
