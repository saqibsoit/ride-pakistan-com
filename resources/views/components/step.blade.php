{{-- <x-step title="Groom">Brush him down and check his hooves.</x-step> --}}
@props(['title', 'level' => 3])

@php($tag = 'h'.max(2, min(6, (int) $level)))

<li {{ $attributes }}>
    <div>
        <{{ $tag }} class="mb-2 text-display-sm">{{ $title }}</{{ $tag }}>
        <div class="text-sand-dim">{{ $slot }}</div>
    </div>
</li>
