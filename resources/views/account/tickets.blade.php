<x-layouts.account title="Tickets" intro="One ticket per rider for each upcoming session. Show it when you arrive.">
    @if ($tickets->isEmpty())
        <p class="text-sand-dim">No tickets yet. Tickets appear here once a booking is confirmed.</p>
    @else
        <ul class="flex flex-col gap-3">
            @foreach ($tickets as $booking)
                <x-account.booking-row :booking="$booking" ticket />
            @endforeach
        </ul>
    @endif
</x-layouts.account>
