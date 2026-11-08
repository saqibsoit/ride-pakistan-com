{{--
    <x-seats-left :seats="2" />  → "2 spots left" + "Selling fast" badge
    - 0 seats: "Fully booked"
    - 1..threshold (setting booking.selling_fast_threshold): Selling fast
    - live: announce changes politely (booking widget)
--}}
@props(['seats', 'threshold' => null, 'live' => false])

@php
    $seats = max(0, (int) $seats);
    $threshold ??= (int) app(\App\Support\Settings::class)->int(\App\Enums\SettingKey::SellingFastThreshold);
    $sellingFast = $seats > 0 && $seats <= $threshold;
@endphp

<p {{ $attributes->class('flex flex-wrap items-center gap-2 text-sm') }} @if ($live) aria-live="polite" @endif>
    @if ($seats === 0)
        <span class="font-semibold text-sand-dim">Fully booked</span>
    @else
        <span class="font-semibold text-sand">{{ $seats }} {{ \Illuminate\Support\Str::plural('spot', $seats) }} left</span>
        @if ($sellingFast)
            <x-badge variant="selling-fast">Selling fast</x-badge>
        @endif
    @endif
</p>
