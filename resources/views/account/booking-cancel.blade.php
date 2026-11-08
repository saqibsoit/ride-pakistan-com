<x-layouts.account title="Cancel a session" :intro="$booking->slot->label().' for '.$booking->rider->name.'. Pakistan time.'">
    @if ($creditBack)
        <x-alert variant="info" title="Your credit comes back.">
            You’re cancelling more than {{ $cutoffHours }} hours before the session, so the credit returns to your package (if it hasn’t expired).
        </x-alert>
    @else
        <x-alert variant="warning" title="Your credit won’t come back.">
            The cutoff was <time datetime="{{ $deadline->toIso8601String() }}">{{ $deadline->setTimezone(config('app.display_timezone'))->format('D j M, g:i a') }}</time> ({{ $cutoffHours }} hours before the session). If you cancel now the credit is used.
        </x-alert>
    @endif

    <form method="POST" action="{{ route('account.bookings.cancel.store', $booking->ticket_code) }}" class="flex flex-wrap gap-3">
        @csrf
        <x-button type="submit" variant="ghost">Yes, cancel this session</x-button>
        <x-button :href="route('account.bookings')">Keep my booking</x-button>
    </form>
</x-layouts.account>
