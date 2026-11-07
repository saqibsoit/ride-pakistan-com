{{--
    <x-button href="/book">Book a session</x-button>
    <x-button variant="ghost" size="sm" type="submit">Send</x-button>
    - variant: primary (sand) | ghost (outline) | dark (earth, for sand surfaces)
    - href renders a link; external adds target=_blank and a screen-reader note
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'external' => false,
])

@php
    $variants = [
        'primary' => 'border-sand bg-sand text-earth hover:bg-transparent hover:text-sand',
        'ghost' => 'border-sand bg-transparent text-sand hover:bg-sand hover:text-earth',
        'dark' => 'border-earth bg-earth text-sand hover:bg-transparent hover:text-earth',
    ];

    $sizes = [
        'sm' => 'min-h-11 px-4.5 py-2.5 text-sm',
        'md' => 'min-h-12 px-6.5 py-3.5 text-base',
    ];

    $classes = 'inline-flex items-center justify-center gap-2 rounded-sm border text-center font-semibold leading-tight no-underline transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-60 '
        .($variants[$variant] ?? $variants['primary']).' '
        .($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        @if ($external) target="_blank" rel="noopener" @endif
        {{ $attributes->class($classes) }}
    >{{ $slot }}@if ($external)<span class="sr-only"> (opens in a new tab)</span>@endif</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
