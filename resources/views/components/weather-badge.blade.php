{{--
    Forecast level of a session in the next 48 hours (milestone 10f).
    <x-weather-badge :session="$slot" />            badge only
    <x-weather-badge :session="$slot" detailed />   badge plus the crossed conditions
    ("session", not "slot": $slot is reserved in Blade components.)
    Renders nothing without an assessment or outside the window. Level is
    shown by icon and text, never colour alone.
--}}
@props(['session', 'detailed' => false])

@php
    $weather = $session->currentWeather();
@endphp

@if ($weather)
    @php
        $severity = $weather->severity;
        $summary = $weather->summary();
    @endphp
    <span {{ $attributes->class('inline-flex flex-wrap items-center gap-2') }}>
        <x-badge :variant="$severity->badgeVariant()">
            <x-glyph :name="$severity->badgeVariant()" class="size-3.5" />
            Weather: {{ $severity->label() }}
        </x-badge>
        @if ($detailed && $summary !== '')
            <span class="text-sm text-sand-dim">{{ $summary }}</span>
        @elseif ($summary !== '')
            <span class="sr-only">({{ $summary }})</span>
        @endif
    </span>
@endif
