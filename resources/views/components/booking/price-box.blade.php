{{--
    Sticky "From PKR X" box for product pages (desktop, lg and up). Pair with
    <x-booking.bottom-bar> for mobile. The default slot holds key facts.

    <x-booking.price-box :from-minor="1000000" unit="per rider" book-href="#book">
        <x-seats-left :seats="3" />
    </x-booking.price-box>
--}}
@props([
    'fromMinor',
    'bookHref',
    'unit' => 'per rider',
    'label' => 'Book a session',
    'note' => null,
])

<aside aria-label="Price and booking" {{ $attributes->class('sticky top-28 hidden flex-col gap-5 rounded-sm border border-line bg-earth-2 p-7 lg:flex') }}>
    <p class="flex flex-col">
        <span class="text-sm text-sand-dim">From</span>
        <x-money :minor="$fromMinor" class="font-display text-display-lg font-black leading-none" />
        @if ($unit)<span class="text-sm text-sand-dim">{{ $unit }}</span>@endif
    </p>
    @if (trim((string) $slot) !== '')
        <div class="flex flex-col gap-3 border-t border-line pt-5">{{ $slot }}</div>
    @endif
    <x-button :href="$bookHref" class="w-full">{{ $label }}</x-button>
    @if ($note)
        <p class="text-xs text-sand-dim">{{ $note }}</p>
    @endif
</aside>
