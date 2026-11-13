<x-mail::message>
# Thanks for riding with us

It was good to have {{ count($riders) === 1 ? $riders[0] : implode(', ', array_slice($riders, 0, -1)).' and '.end($riders) }} with us on {{ $slot->starts_at->setTimezone(config('app.display_timezone'))->format('l j M') }}. We hope you enjoyed the ride.

@if ($siteReviewUrl)
How was it? A rating and a few words help other beginners decide, and help us get better. No sign-in needed; the link works for {{ \App\Support\ReviewLink::VALID_DAYS }} days.

<x-mail::button :url="$siteReviewUrl" color="primary">
Rate your session
</x-mail::button>
@endif

@if ($reviewUrl !== '')
A short Google review helps people find us too.

<x-mail::button :url="$reviewUrl">
Review us on Google
</x-mail::button>
@endif

Ready for the next one?

<x-mail::button :url="route('schedule')">
See the schedule
</x-mail::button>

@include('mail.partials.manage-link', ['for' => $bookings])

Ride Pakistan
</x-mail::message>
