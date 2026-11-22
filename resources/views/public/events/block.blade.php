{{-- One event content block (RideEvent::$blocks), shown only when it has content. --}}
@php
    $type = $block['type'] ?? null;
    $data = is_array($block['data'] ?? null) ? $block['data'] : [];
    $strings = fn (mixed $list): array => array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', (array) $list)));
    $id = 'block-'.$i;
@endphp

@switch($type)
    @case('programme')
        @php($items = array_values(array_filter((array) ($data['items'] ?? []), fn ($s) => is_array($s) && ! empty($s['activity']))))
        @if ($items !== [])
            <section aria-labelledby="{{ $id }}">
                <h2 id="{{ $id }}" class="mb-8 text-display-lg">The programme.</h2>
                <x-step-list>
                    @foreach ($items as $step)
                        <x-step :title="(string) ($step['time'] ?? '')">{{ $step['activity'] }}</x-step>
                    @endforeach
                </x-step-list>
            </section>
        @endif
        @break

    @case('included')
        @php($in = $strings($data['included'] ?? []))
        @php($out = $strings($data['not_included'] ?? []))
        @if ($in !== [] || $out !== [])
            <section aria-labelledby="{{ $id }}" class="grid gap-8 md:grid-cols-2">
                <h2 id="{{ $id }}" class="sr-only">What’s included</h2>
                @if ($in !== [])
                    <div>
                        <h3 class="mb-6 text-display-md">Your booking includes.</h3>
                        <ul class="tick-list">@foreach ($in as $item)<li>{{ $item }}</li>@endforeach</ul>
                    </div>
                @endif
                @if ($out !== [])
                    <div>
                        <h3 class="mb-6 text-display-md">Not included.</h3>
                        <ul class="flex list-disc flex-col gap-2 ps-5 text-sand-dim">@foreach ($out as $item)<li>{{ $item }}</li>@endforeach</ul>
                    </div>
                @endif
            </section>
        @endif
        @break

    @case('bring')
        @php($items = $strings($data['items'] ?? []))
        @if ($items !== [])
            <section aria-labelledby="{{ $id }}">
                <h2 id="{{ $id }}" class="mb-6 text-display-lg">What to bring.</h2>
                <ul class="tick-list max-w-prose">@foreach ($items as $item)<li>{{ $item }}</li>@endforeach</ul>
            </section>
        @endif
        @break

    @case('dress_code')
        @php($items = $strings($data['items'] ?? []))
        @php($line = is_string($data['text'] ?? null) ? trim($data['text']) : '')
        @if ($items !== [] || $line !== '')
            <section aria-labelledby="{{ $id }}" class="rounded-sm border border-line-strong bg-earth-2 p-7">
                <h2 id="{{ $id }}" class="mb-4 text-display-lg">Dress code.</h2>
                @if ($line !== '')
                    <p class="mb-4 font-display text-display-sm font-extrabold">{{ $line }}</p>
                @endif
                @if ($items !== [])
                    <ul class="tick-list max-w-prose">@foreach ($items as $item)<li>{{ $item }}</li>@endforeach</ul>
                @endif
                <p class="mt-4 text-sm text-sand-dim">You’ll confirm this when you book.</p>
            </section>
        @endif
        @break

    @case('requirements')
        {{-- Shown in the key facts. --}}
        @break

    @case('faq')
        @php($items = array_values(array_filter((array) ($data['items'] ?? []), fn ($q) => is_array($q) && ! empty($q['question']) && ! empty($q['answer']))))
        @if ($items !== [])
            <section aria-labelledby="{{ $id }}">
                <h2 id="{{ $id }}" class="mb-8 text-display-lg">Questions.</h2>
                @foreach ($items as $q)
                    <x-faq-item :question="$q['question']" group="event-faq">{{ $q['answer'] }}</x-faq-item>
                @endforeach
            </section>
        @endif
        @break

    @case('location')
        @if (! empty($data['text']))
            <section aria-labelledby="{{ $id }}">
                <h2 id="{{ $id }}" class="mb-6 text-display-lg">Getting there.</h2>
                <p class="max-w-prose text-sand-dim">{{ $data['text'] }}</p>
            </section>
        @endif
        @break

    @case('photos')
        @php($images = array_values(array_filter((array) ($data['images'] ?? []), fn ($p) => is_array($p) && ! empty($p['image']))))
        @if ($images !== [])
            <section aria-labelledby="{{ $id }}">
                <h2 id="{{ $id }}" class="mb-6 text-display-lg">{{ ! empty($data['heading']) ? $data['heading'] : 'Photos.' }}</h2>
                <ul class="grid grid-cols-2 gap-3 md:grid-cols-3" role="list">
                    @foreach ($images as $photo)
                        <li><x-img :src="$photo['image']" :alt="(string) ($photo['alt'] ?? '')" sizes="(min-width: 48rem) 20vw, 50vw" class="aspect-4/3 w-full object-cover" /></li>
                    @endforeach
                </ul>
            </section>
        @endif
        @break
@endswitch
