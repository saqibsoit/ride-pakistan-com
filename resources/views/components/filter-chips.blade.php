{{--
    <x-filter-chips label="Filter experiences">
        <x-filter-chip href="/experiences" active>All</x-filter-chip>
        <x-filter-chip href="/experiences?type=lessons">Lessons</x-filter-chip>
    </x-filter-chips>
--}}
@props(['label'])

<div role="group" aria-label="{{ $label }}" {{ $attributes->class('flex flex-wrap gap-2') }}>
    {{ $slot }}
</div>
