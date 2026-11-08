<x-mail::message>
# Thanks for riding with us

It was good to have {{ count($riders) === 1 ? $riders[0] : implode(', ', array_slice($riders, 0, -1)).' and '.end($riders) }} with us on {{ $slot->starts_at->setTimezone(config('app.display_timezone'))->format('l j M') }}. We hope you enjoyed the ride.

@if ($reviewUrl !== '')
If you have a minute, a short Google review helps other beginners find us.

<x-mail::button :url="$reviewUrl" color="primary">
Leave a review
</x-mail::button>
@endif

Ready for the next one?

<x-mail::button :url="route('schedule')">
See the schedule
</x-mail::button>

Ride Pakistan
</x-mail::message>
