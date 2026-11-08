{{--
    Week-calendar slot card. Times are stored in UTC and shown in Asia/Karachi.

    <x-slot-card :starts-at="$slot->starts_at" :ends-at="$slot->ends_at" product="Single session"
                 trainer="Omar Saleem" :seats="3" href="/book?slot=..." />

    The whole card is one link (stretched); with 0 seats it is not a link.
    seats=null (before live availability exists) shows `note` instead.
--}}
@props([
    'startsAt',
    'endsAt',
    'product',
    'seats' => null,
    'note' => 'Seats shown when online booking opens',
    'trainer' => null,
    'href' => null,
    'level' => 3,
])

@php
    $tz = (string) config('app.display_timezone');
    $start = \Illuminate\Support\Carbon::instance($startsAt)->setTimezone($tz);
    $end = \Illuminate\Support\Carbon::instance($endsAt)->setTimezone($tz);
    $time = $start->format('g:i a').' – '.$end->format('g:i a');
    $bookable = $href && ($seats === null || (int) $seats > 0);
    $tag = 'h'.max(2, min(6, (int) $level));
@endphp

<article {{ $attributes->class([
    'relative flex flex-col gap-3 rounded-sm border bg-earth-2 p-5 transition-colors',
    'border-line-strong hover:border-sand' => $bookable,
    'border-line' => ! $bookable,
]) }}>
    <{{ $tag }} class="font-display text-display-xs font-extrabold">
        @if ($bookable)
            <a href="{{ $href }}" class="after:absolute after:inset-0">
                <time datetime="{{ $start->toIso8601String() }}">{{ $time }}</time>
                <span class="sr-only">, {{ $start->format('l j F') }}, book {{ $product }}</span>
            </a>
        @else
            <time datetime="{{ $start->toIso8601String() }}">{{ $time }}</time>
            <span class="sr-only">, {{ $start->format('l j F') }}</span>
        @endif
    </{{ $tag }}>
    <p class="font-semibold">{{ $product }}</p>
    @if ($trainer)
        <p class="text-sm text-sand-dim">with {{ $trainer }}</p>
    @endif
    @if ($seats === null)
        <p class="text-sm text-sand-dim">{{ $note }}</p>
    @else
        <x-seats-left :seats="$seats" />
    @endif
</article>
