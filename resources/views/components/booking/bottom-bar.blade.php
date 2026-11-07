{{--
    Fixed bottom booking bar for phones and tablets (below lg). The page gets
    matching bottom padding automatically (see .bottom-bar in app.css).

    <x-booking.bottom-bar :from-minor="1000000" book-href="#book" />
--}}
@props([
    'fromMinor',
    'bookHref',
    'unit' => 'per rider',
    'label' => 'Book now',
])

<aside aria-label="Price and booking" {{ $attributes->class('bottom-bar fixed inset-x-0 bottom-0 z-30 border-t border-line bg-earth/95 pt-3 backdrop-blur-md lg:hidden') }}>
    <div class="wrap flex items-center justify-between gap-4">
        <p class="flex flex-col leading-tight">
            <span class="text-xs text-sand-dim">From</span>
            <x-money :minor="$fromMinor" class="font-display text-display-xs font-black" />
            @if ($unit)<span class="text-xs text-sand-dim">{{ $unit }}</span>@endif
        </p>
        <x-button :href="$bookHref">{{ $label }}</x-button>
    </div>
</aside>
