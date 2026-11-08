<x-mail::message>
# {{ $heading }}

**{{ $slot->label() }}** (Pakistan time)

@foreach ($paragraphs as $paragraph)
{!! nl2br(e($paragraph)) !!}

@endforeach
@if ($bookings->isNotEmpty())
Your riders in this session:

@foreach ($bookings as $booking)
- {{ $booking->rider->name }} (ticket {{ $booking->ticket_code }})
@endforeach
@endif

Questions? Reply to this email or write to {{ $contactEmail }}.

Ride Pakistan
</x-mail::message>
