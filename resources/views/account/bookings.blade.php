<x-layouts.account title="Bookings" intro="All times are Pakistan time.">
    <section aria-labelledby="upcoming-title" class="flex flex-col gap-4">
        <h2 id="upcoming-title" class="text-display-xs">Upcoming</h2>
        @if ($upcoming->isEmpty())
            <p class="text-sand-dim">No upcoming sessions. <a href="{{ route('schedule') }}" class="underline underline-offset-4 hover:text-sand">See the schedule</a>.</p>
        @else
            <ul class="flex flex-col gap-3">
                @foreach ($upcoming as $booking)
                    <x-account.booking-row :booking="$booking" ticket />
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="past-title" class="flex flex-col gap-4">
        <h2 id="past-title" class="text-display-xs">Past</h2>
        @if ($past->isEmpty())
            <p class="text-sand-dim">No past sessions yet.</p>
        @else
            <ul class="flex flex-col gap-3">
                @foreach ($past as $booking)
                    <x-account.booking-row :booking="$booking" />
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.account>
