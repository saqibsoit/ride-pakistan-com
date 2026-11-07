{{--
    One chip in <x-filter-chips>. With href it's a link (aria-current when
    active); without, a toggle button (aria-pressed) for client-side filters.
--}}
@props(['href' => null, 'active' => false, 'count' => null])

@php
    $classes = 'inline-flex min-h-11 items-center gap-2 rounded-sm border px-4 py-2 text-sm font-semibold no-underline transition-colors '
        .($active ? 'border-sand bg-sand text-earth' : 'border-line-strong text-sand hover:border-sand');
@endphp

@if ($href)
    <a href="{{ $href }}" @if ($active) aria-current="true" @endif {{ $attributes->class($classes) }}>
        {{ $slot }}@if ($count !== null)<span class="font-normal opacity-80">({{ $count }})</span>@endif
    </a>
@else
    <button type="button" aria-pressed="{{ $active ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>
        {{ $slot }}@if ($count !== null)<span class="font-normal opacity-80">({{ $count }})</span>@endif
    </button>
@endif
