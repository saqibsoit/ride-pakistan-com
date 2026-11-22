<x-mail::message>
# Lesson bookings to move

**{{ $occurrence->rideEvent->title ?? 'An event' }}** on {{ $occurrence->dateRangeLabel() }} closes the regular lessons {{ $occurrence->lesson_closure->value === 'day' ? 'that day' : 'it overlaps' }}. These lesson bookings were already made and have **not** been cancelled. Please move them (or agree something else with the customer):

@foreach ($bookings as $booking)
- {{ $booking->slot->label() }}: {{ $booking->rider->name ?? 'Rider' }} ({{ $booking->user->email ?? 'no email' }}), ticket {{ $booking->ticket_code }}
@endforeach

@if ($eventUrl)
<x-mail::button :url="$eventUrl">
Open the event
</x-mail::button>
@endif

Ride Pakistan
</x-mail::message>
