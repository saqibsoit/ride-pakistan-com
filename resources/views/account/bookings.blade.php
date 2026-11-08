<x-layouts.account title="Bookings" intro="All times are Pakistan time.">
    @php($flash = [
        'booked-with-credits' => 'Booked with your credits. Your tickets are below.',
        'booking-moved' => 'Your session has been moved.',
        'booking-cancelled-credit-back' => 'Cancelled. Your credit is back in your package.',
        'booking-cancelled' => 'Cancelled. The credit was used because it was past the cutoff.',
    ][session('status')] ?? null)
    @if ($flash)
        <x-alert variant="success">{{ $flash }}</x-alert>
    @endif
    @if ($errors->has('booking'))
        <x-alert variant="error">{{ $errors->first('booking') }}</x-alert>
    @endif
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
