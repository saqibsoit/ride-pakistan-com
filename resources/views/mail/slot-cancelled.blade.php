<x-mail::message>
# Your session is cancelled

We're sorry: we've had to cancel the session on **{{ $slot->label() }}** (Pakistan time).

@if ($reason)
**Why:** {{ $reason }}
@endif

@if ($bookings->isNotEmpty())
Cancelled tickets:

@foreach ($bookings as $booking)
- {{ $booking->rider->name }} (ticket {{ $booking->ticket_code }})
@endforeach
@else
Your booking for this session, which wasn't paid yet, has been cancelled.
@endif

@if ($creditsReturned > 0)
{{ $creditsReturned === 1 ? 'The credit has' : 'The '.$creditsReturned.' credits have' }} gone back to your package, so you can book another session straight away.
@endif

@if ($paidBookings)
For the sessions you paid for, we'll be in touch within a few days to move you to another session or refund you. You don't need to do anything. If you'd like a particular date, look at the schedule and email us at {{ app(\App\Support\Settings::class)->string(\App\Enums\SettingKey::Email) }} with your choice.
@endif

@if ($creditsReturned > 0)
<x-mail::button :url="$rebookUrl" color="primary">
Book with your credits
</x-mail::button>
@else
<x-mail::button :url="route('schedule')" color="primary">
See the schedule
</x-mail::button>
@endif

Ride Pakistan
</x-mail::message>
