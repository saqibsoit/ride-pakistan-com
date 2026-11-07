{{--
    <x-section-heading eyebrow="Pricing" title="Sessions and prices." lede="..." />
    Title can also be passed as a named slot for line breaks:
    <x-section-heading><x-slot:title>Two hours,<br>start to finish.</x-slot:title></x-section-heading>
--}}
@props([
    'title' => null,
    'eyebrow' => null,
    'lede' => null,
    'level' => 2,
    'size' => 'xl',
    'id' => null,
])

@php
    $tag = 'h'.max(1, min(6, (int) $level));
    $sizes = [
        'xl' => 'text-display-xl',
        'lg' => 'text-display-lg',
        'md' => 'text-display-md',
    ];
@endphp

<div {{ $attributes->class('flex flex-col') }}>
    @if ($eyebrow)
        <p class="mb-4 text-xs font-semibold uppercase tracking-label text-sand-dim">{{ $eyebrow }}</p>
    @endif
    <{{ $tag }} @if ($id) id="{{ $id }}" @endif class="{{ $sizes[$size] ?? $sizes['xl'] }}">{{ $title }}</{{ $tag }}>
    @if ($lede)
        <p class="mt-5 max-w-prose text-lede text-sand-dim">{{ $lede }}</p>
    @endif
    {{ $slot }}
</div>
