{{-- One fact in <x-fact-strip>. Screen readers hear "label: value"; the value shows first visually. --}}
@props(['value', 'label'])

<div {{ $attributes->class('flex flex-col-reverse justify-end gap-1') }}>
    <dt class="text-sm text-sand-dim">{{ $label }}</dt>
    <dd class="font-display text-display-sm font-extrabold">{{ $value }}</dd>
</div>
