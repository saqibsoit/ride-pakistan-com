{{--
    Labelled form control with hint and error, wired with aria-describedby.

    <x-form.field name="email" label="Email" type="email" hint="We send your ticket here." required autocomplete="email" />
    <x-form.field name="message" label="Message" type="textarea" rows="5" />
    <x-form.field name="slot" label="Time" type="select"><option value="am">8–10 am</option></x-form.field>

    The error comes from the validation bag ($errors) unless passed as `error`.
    Extra attributes go on the control. Dot names (riders.0.name) become array names.
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'id' => null,
    'hint' => null,
    'error' => null,
    'value' => null,
    'required' => false,
])

@php
    $id ??= 'field-'.\Illuminate\Support\Str::slug(str_replace('.', '-', $name));
    $htmlName = \Illuminate\Support\Str::contains($name, '.')
        ? preg_replace('/\.([^.]+)/', '[$1]', $name)
        : $name;
    $message = $error ?? (isset($errors) ? $errors->first($name) : null);
    $message = $message === '' ? null : $message;
    $hintId = $hint ? $id.'-hint' : null;
    $errorId = $message ? $id.'-error' : null;
    $describedBy = trim(implode(' ', array_filter([$hintId, $errorId])));
    $current = $type === 'password' ? null : old($name, $value);

    $controlClasses = 'block w-full rounded-sm border bg-earth-2 px-4 py-3 text-base text-sand placeholder:text-sand-dim '
        .($message ? 'border-error' : 'border-line-strong hover:border-sand');
@endphp

<div class="flex flex-col gap-2">
    <label for="{{ $id }}" class="text-sm font-semibold text-sand">
        {{ $label }}
        @unless ($required)<span class="font-normal text-sand-dim">(optional)</span>@endunless
    </label>

    @if ($hint)
        <p id="{{ $hintId }}" class="text-sm text-sand-dim">{{ $hint }}</p>
    @endif

    @if ($type === 'textarea')
        <textarea
            id="{{ $id }}"
            name="{{ $htmlName }}"
            @if ($required) required @endif
            @if ($message) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class($controlClasses) }}
        >{{ $current }}</textarea>
    @elseif ($type === 'select')
        <select
            id="{{ $id }}"
            name="{{ $htmlName }}"
            @if ($required) required @endif
            @if ($message) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class($controlClasses) }}
        >{{ $slot }}</select>
    @else
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            name="{{ $htmlName }}"
            @if ($current !== null) value="{{ $current }}" @endif
            @if ($required) required @endif
            @if ($message) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class($controlClasses) }}
        >
    @endif

    @if ($message)
        <p id="{{ $errorId }}" class="flex items-start gap-2 text-sm text-error">
            <x-glyph name="error" class="mt-0.5 size-4" />
            <span><span class="sr-only">Error: </span>{{ $message }}</span>
        </p>
    @endif
</div>
