{{--
    <x-badge variant="selling-fast">Selling fast</x-badge>
    - neutral | featured | selling-fast | price | success | warning | error
    Label badges are small caps-style uppercase; the price badge reads as normal text.
--}}
@props(['variant' => 'neutral'])

@php
    $label = 'text-xs uppercase tracking-label';
    $variants = [
        'neutral' => "border border-line-strong text-sand {$label}",
        'featured' => "bg-sand text-earth {$label}",
        'selling-fast' => "bg-saddle text-sand {$label}",
        'price' => 'bg-earth text-sm text-sand',
        'success' => "border border-success text-success {$label}",
        'warning' => "border border-warning text-warning {$label}",
        'error' => "border border-error text-error {$label}",
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-sm px-2.5 py-1 font-semibold leading-tight',
    $variants[$variant] ?? $variants['neutral'],
]) }}>{{ $slot }}</span>
