{{--
    <x-fact-strip label="Session facts">
        <x-fact value="Sat & Sun" label="Every weekend" />
        ...
    </x-fact-strip>
--}}
@props(['label' => null])

<div {{ $attributes->class('border-y border-line') }}>
    <dl class="wrap fact-strip" @if ($label) aria-label="{{ $label }}" @endif>
        {{ $slot }}
    </dl>
</div>
