{{--
    Where we ride: venue name, "Get directions" and a click-to-load Google map.
    <x-venue-map />            card with heading (h2)
    <x-venue-map :level="3" /> heading level inside a page section
    Nothing from Google loads until the visitor presses "Show map" (no cookies
    before that, so no consent is needed). The iframe origin is the
    `google_maps` entry in config/csp.php. Renders nothing without a map link.
--}}
@props(['level' => 2, 'id' => 'where-title'])

@php
    $facts = app(\App\Support\BusinessFacts::class);
    $mapUrl = $facts->mapUrl();
    $embedUrl = $facts->mapEmbedUrl();
    $tag = 'h'.max(1, min(6, (int) $level));
@endphp

@if ($mapUrl !== null)
<div {{ $attributes->class('venue-map flex flex-col gap-5') }} @if ($embedUrl !== null) x-data="mapEmbed" @endif>
    <div class="flex flex-col gap-2">
        <{{ $tag }} id="{{ $id }}" class="text-display-sm">Where we ride.</{{ $tag }}>
        <p class="flex items-center gap-2 text-sand-dim">
            <x-glyph name="pin" class="size-4 shrink-0" />
            {{ $facts->venueWithLocation() }}
        </p>
    </div>

    @if ($embedUrl !== null)
        <div class="overflow-hidden rounded-sm border border-line bg-earth-2">
            <div x-show="!open" class="flex aspect-4/3 flex-col items-center justify-center gap-4 p-6 text-center md:aspect-video">
                <p class="max-w-prose text-sm text-sand-dim">The map is served by Google, which may set cookies once it loads.</p>
                <x-button variant="ghost" size="sm" x-on:click="show" aria-controls="{{ $id }}-frame">Show map</x-button>
            </div>
            <template x-if="open">
                <iframe
                    id="{{ $id }}-frame"
                    x-ref="frame"
                    src="{{ $embedUrl }}"
                    title="Map of {{ $facts->venueWithLocation() }}"
                    class="block aspect-4/3 w-full border-0 md:aspect-video"
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allowfullscreen
                ></iframe>
            </template>
        </div>
    @endif

    <div>
        <x-button :href="$mapUrl" external size="sm">Get directions</x-button>
    </div>
</div>
@endif
