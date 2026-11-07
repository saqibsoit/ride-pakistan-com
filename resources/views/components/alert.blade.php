{{--
    <x-alert variant="error" title="Payment failed">Your card was declined.</x-alert>
    - info | success | warning | error. Error uses role="alert"; others role="status".
    Status never relies on colour alone: icon + title (or a hidden prefix).
--}}
@props(['variant' => 'info', 'title' => null])

@php
    $styles = [
        'info' => ['border' => 'border-sand', 'icon' => 'text-sand', 'label' => 'Note'],
        'success' => ['border' => 'border-success', 'icon' => 'text-success', 'label' => 'Success'],
        'warning' => ['border' => 'border-warning', 'icon' => 'text-warning', 'label' => 'Warning'],
        'error' => ['border' => 'border-error', 'icon' => 'text-error', 'label' => 'Error'],
    ];
    $style = $styles[$variant] ?? $styles['info'];
    $iconName = array_key_exists($variant, $styles) ? $variant : 'info';
@endphp

<div role="{{ $variant === 'error' ? 'alert' : 'status' }}" {{ $attributes->class(['flex gap-3 rounded-sm border-l-3 bg-earth-2 p-5', $style['border']]) }}>
    <x-glyph :name="$iconName" class="mt-0.5 size-5 {{ $style['icon'] }}" />
    <div class="text-sm">
        @if ($title)
            <p class="font-semibold text-sand"><span class="sr-only">{{ $style['label'] }}: </span>{{ $title }}</p>
            <div class="mt-1 text-sand-dim">{{ $slot }}</div>
        @else
            <div class="text-sand"><span class="sr-only">{{ $style['label'] }}: </span>{{ $slot }}</div>
        @endif
    </div>
</div>
