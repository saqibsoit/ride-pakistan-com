{{--
    <x-price-row title="Single session" :amount-minor="1000000" caption="per rider" summary="One 2-hour session.">
        The full experience: groundwork, grooming, tacking up and a guided ride.
    </x-price-row>
    Use inside a list with a top rule (see styleguide). featured adds the saddle wash.
--}}
@props([
    'title',
    'amountMinor',
    'caption' => null,
    'summary' => null,
    'featured' => false,
    'label' => null,
    'level' => 3,
])

@php($tag = 'h'.max(2, min(6, (int) $level)))

<div {{ $attributes->class([
    'price-row border-b border-line py-10',
    'price-row-featured -mx-6 px-6' => $featured,
]) }}>
    <div>
        @if ($label)
            <x-badge variant="featured" class="mb-3">{{ $label }}</x-badge>
        @endif
        <{{ $tag }} class="text-display-md">{{ $title }}</{{ $tag }}>
    </div>
    <p class="text-sand-dim">
        @if ($summary)<strong class="font-semibold text-sand">{{ $summary }}</strong>@endif
        {{ $slot }}
    </p>
    <p class="whitespace-nowrap md:text-right">
        <x-money :minor="$amountMinor" class="block font-display text-display-lg font-black leading-none" />
        @if ($caption)<span class="text-sm text-sand-dim">{{ $caption }}</span>@endif
    </p>
</div>
