{{-- Cancel an event booking from the manage link (decided 2026-11-22): what comes back is shown first. --}}
@php($tz = config('app.display_timezone'))
<x-layouts.app :title="'Cancel '.$order->reference" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="cancel-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Booking {{ $order->reference }}</p>
        <h1 id="cancel-title" class="mt-2 text-display-lg">Cancel your booking?</h1>

        <div class="mt-8 flex flex-col gap-4 rounded-sm border border-line-strong bg-earth-2 p-6">
            <p class="font-semibold">{{ $order->productName() }}{{ $event ? ', '.$event->dateLabel() : '' }}</p>
            <p>Everyone on this booking is cancelled{{ $event && count($event->people()) > 1 ? ' ('.collect($event->people())->pluck('name')->join(', ').')' : '' }}.</p>
            @if ($quote['refund'] > 0)
                <p class="font-display text-display-sm font-extrabold">You get back <x-money :minor="$quote['refund']" :currency="$order->currency" />.</p>
            @else
                <p class="font-display text-display-sm font-extrabold">There is no refund.</p>
            @endif
            <p class="text-sm text-sand-dim">
                @if ($order->deposit_percent !== null)
                    The deposit is non-refundable.
                @endif
                @if ($quote['cutoff'])
                    {{ $quote['before_cutoff'] ? 'You are cancelling before the cutoff ('.$quote['cutoff']->setTimezone($tz)->format('D j M Y, H:i').').' : 'The cutoff for a refund was '.$quote['cutoff']->setTimezone($tz)->format('D j M Y, H:i').'.' }}
                @endif
                Any balance not yet paid is no longer owed.
            </p>
        </div>

        <form method="POST" action="{{ route('manage.event.cancel.store', $order->reference) }}" class="mt-8 flex flex-wrap gap-3">
            @csrf
            <x-button type="submit">Yes, cancel my booking</x-button>
            <x-button :href="route('manage.show', $order->reference)" variant="ghost">Keep my booking</x-button>
        </form>
    </section>
</x-layouts.app>
