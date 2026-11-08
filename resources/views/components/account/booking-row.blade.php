{{--
    One booking in the account area: date and time (Pakistan time), rider,
    status, and a link to the ticket when it is confirmed and upcoming.
    <x-account.booking-row :booking="$booking" :ticket="true" />
--}}
@props(['booking', 'ticket' => false])

@php
    $slot = $booking->slot;
    $confirmed = $booking->status === \App\Enums\BookingStatus::Confirmed;
@endphp

<li {{ $attributes->class('flex flex-wrap items-center justify-between gap-4 rounded-sm border border-line bg-earth-2 p-5') }}>
    <div class="flex flex-col gap-1">
        <p class="font-semibold text-sand">
            <time datetime="{{ $slot->starts_at->toIso8601String() }}">{{ $slot->label() }}</time>
        </p>
        <p class="text-sm text-sand-dim">Rider: {{ $booking->rider->name }}</p>
        @if ($ticket && $confirmed && $slot->starts_at->isFuture())
            {{-- Add to calendar (milestone 10d): own bookings only, the file carries the map pin. --}}
            <p class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <span class="text-sand-dim">Add to calendar:</span>
                <a href="{{ app(\App\Support\Calendar\GoogleCalendarLink::class)->for($booking) }}" target="_blank" rel="noopener" class="text-sand underline underline-offset-4">Google<span class="sr-only"> Calendar, {{ $slot->label() }} (opens in a new tab)</span></a>
                <a href="{{ route('account.bookings.calendar', $booking->ticket_code) }}" class="text-sand underline underline-offset-4" download>Apple / Outlook (.ics)<span class="sr-only"> for {{ $slot->label() }}</span></a>
            </p>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-3">
        <x-badge :variant="$confirmed ? 'success' : 'neutral'">{{ $booking->status->label() }}</x-badge>
        @if ($ticket && $confirmed)
            <x-button :href="route('account.tickets.show', $booking->ticket_code)" variant="ghost" size="sm">
                View ticket<span class="sr-only"> for {{ $slot->label() }}</span>
            </x-button>
            @can('change', $booking)
                <x-button :href="route('account.bookings.reschedule.edit', $booking->ticket_code)" variant="ghost" size="sm">Move<span class="sr-only"> {{ $slot->label() }}</span></x-button>
                <x-button :href="route('account.bookings.cancel', $booking->ticket_code)" variant="ghost" size="sm">Cancel<span class="sr-only"> {{ $slot->label() }}</span></x-button>
            @else
                @if ($booking->slot->starts_at->isFuture())
                    <span class="text-sm text-sand-dim">To change, <a href="{{ route('contact') }}" class="underline underline-offset-4">contact us</a></span>
                @endif
            @endcan
        @endif
    </div>
</li>
