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
