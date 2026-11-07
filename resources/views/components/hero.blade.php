{{--
    Full-height photo hero. The headline uses --text-hero, which scales with
    viewport width AND height so it never collides with the header.

    <x-hero image="hero.jpg" image-alt="..." :lines="['Never touched', 'a horse?', 'Perfect.']" lede="...">
        <x-button href="/prices">See sessions and prices</x-button>
    </x-hero>

    Use with <x-layouts.app overlay-header>. Default slot = calls to action.
--}}
@props([
    'image',
    'imageAlt',
    'lines' => [],
    'lede' => null,
    'focus' => 'focus',
])

@php
    $positions = [
        'focus' => 'hero-image-focus',
        'center' => 'object-center',
        'top' => 'object-top',
    ];
@endphp

<section {{ $attributes->class('hero-scrim relative flex min-h-svh items-end overflow-hidden') }}>
    <x-img
        :src="$image"
        :alt="$imageAlt"
        sizes="100vw"
        priority
        class="absolute inset-0 size-full object-cover motion-safe:animate-settle {{ $positions[$focus] ?? $positions['focus'] }}"
    />
    <div class="wrap hero-content relative z-10">
        {{-- Explicit lines set their own breaks; free text is capped at 10ch. --}}
        <h1 @class(['text-hero', 'max-w-headline' => $lines === []])>
            @foreach ($lines as $line)
                <span class="block">{{ $line }}</span>
            @endforeach
            {{ $title ?? '' }}
        </h1>
        @if ($lede)
            <p class="mt-7 max-w-prose text-lede text-sand">{{ $lede }}</p>
        @endif
        @if (trim((string) $slot) !== '')
            <div class="mt-9 flex flex-wrap gap-3.5">{{ $slot }}</div>
        @endif
    </div>
</section>
