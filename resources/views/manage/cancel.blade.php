{{-- Cancel a credit booking from the manage page (guest checkout); same rules as the account page. --}}
<x-layouts.app title="Cancel a session" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="cancel-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Order {{ $order->reference }}</p>
        <h1 id="cancel-title" class="mt-2 text-display-lg">Cancel a session</h1>
        <p class="mt-2 text-sand-dim">{{ $booking->slot->label() }} for {{ $booking->rider->name }}. Pakistan time.</p>

        <div class="mt-8">
            @if ($creditBack)
                <x-alert variant="info" title="Your credit comes back.">
                    You’re cancelling more than {{ $cutoffHours }} hours before the session, so the credit returns to your package (if it hasn’t expired).
                </x-alert>
            @else
                <x-alert variant="warning" title="Your credit won’t come back.">
                    The cutoff was <time datetime="{{ $deadline->toIso8601String() }}">{{ $deadline->setTimezone(config('app.display_timezone'))->format('D j M, g:i a') }}</time> ({{ $cutoffHours }} hours before the session). If you cancel now the credit is used.
                </x-alert>
            @endif
        </div>

        <form method="POST" action="{{ route('manage.cancel.store', [$order->reference, $booking->ticket_code]) }}" class="mt-8 flex flex-wrap gap-3">
            @csrf
            <x-button type="submit" variant="ghost">Yes, cancel this session</x-button>
            <x-button :href="route('manage.show', $order->reference)">Keep my booking</x-button>
        </form>
    </section>
</x-layouts.app>
