<x-mail::message>
# You're booked

@php($first = $bookings->first())
{{ $bookings->count() === 1 ? 'Your session is' : 'Your sessions are' }} confirmed for **{{ $first->slot->label() }}** (Pakistan time).

## Your tickets

Show the code at the gate. Staff scan it to check you in.

@foreach ($bookings as $booking)
**{{ $booking->rider->name }}**: ticket **{{ $booking->ticket_code }}** · [Add to Google Calendar]({{ $calendar[$booking->id] }})
@isset($message)
<br><img src="{{ $message->embedData($qr[$booking->id], 'ticket-'.$booking->ticket_code.'.png', 'image/png') }}" alt="QR code for ticket {{ $booking->ticket_code }}" width="180" height="180">
@endisset

@endforeach
The ticket images are also attached, and each ticket is in your account. To add the session to Apple Calendar or Outlook, open the attached .ics file.

@if ($first->creditPack)
**Your package:** {{ $first->creditPack->creditsRemaining() }} {{ $first->creditPack->creditsRemaining() === 1 ? 'credit' : 'credits' }} left, valid until {{ $first->creditPack->expires_at->setTimezone($timezone)->format('j M Y') }}.
@endif

@if ($helmet !== '')
## Before you come

**{{ $helmet }}**
@endif

@if ($mapPin !== '')
## Where to find us

Here's the exact location for your session in Islamabad (please don't share it publicly):

<x-mail::button :url="$mapPin">
Open the map pin
</x-mail::button>
@endif

<x-mail::button :url="route('account.bookings')" color="primary">
See your bookings
</x-mail::button>

Ride Pakistan
</x-mail::message>
