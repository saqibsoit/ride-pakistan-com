{{-- <x-account.order-status :status="$order->status" />: text label, colour only as a supplement. --}}
@props(['status'])

@php
    $variant = match ($status->color()) {
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'error',
        default => 'neutral',
    };
@endphp

<x-badge :variant="$variant" {{ $attributes }}>{{ $status->label() }}</x-badge>
