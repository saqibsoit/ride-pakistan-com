{{--
    Renders CMS body blocks (pages and guides). Text is Markdown, converted
    with raw HTML stripped and unsafe links blocked (App\Support\Markdown).

    Block types:
    - text:    heading?, content (Markdown)
    - image:   image (path under public/images), alt, caption?
    - quote:   text (pull quote)
    - callout: title?, content (Markdown)
    - cta:     label, url, text?
--}}
@props(['blocks' => []])

<div {{ $attributes->class('flex flex-col gap-12') }}>
    @foreach ($blocks ?? [] as $block)
        @php($data = $block['data'] ?? [])
        @switch($block['type'] ?? null)
            @case('text')
                <section class="prose-content">
                    @if (filled($data['heading'] ?? null))
                        <h2>{{ $data['heading'] }}</h2>
                    @endif
                    {!! \App\Support\Markdown::toHtml((string) ($data['content'] ?? '')) !!}
                </section>
                @break

            @case('image')
                @if (filled($data['image'] ?? null))
                    <figure>
                        <x-img :src="$data['image']" :alt="(string) ($data['alt'] ?? '')" sizes="(min-width: 64rem) 54rem, 100vw" class="w-full object-cover" />
                        @if (filled($data['caption'] ?? null))
                            <figcaption class="mt-3 text-sm text-sand-dim">{{ $data['caption'] }}</figcaption>
                        @endif
                    </figure>
                @endif
                @break

            @case('quote')
                <blockquote class="border-l-3 border-saddle pl-6 font-display text-display-sm font-extrabold">
                    <p>{{ $data['text'] ?? '' }}</p>
                </blockquote>
                @break

            @case('callout')
                <aside class="rounded-sm border border-line bg-earth-2 p-7">
                    @if (filled($data['title'] ?? null))
                        <h2 class="mb-3 text-display-xs">{{ $data['title'] }}</h2>
                    @endif
                    <div class="prose-content">{!! \App\Support\Markdown::toHtml((string) ($data['content'] ?? '')) !!}</div>
                </aside>
                @break

            @case('cta')
                @if (filled($data['url'] ?? null))
                    <div class="flex flex-col items-start gap-4">
                        @if (filled($data['text'] ?? null))
                            <p class="max-w-prose text-lede text-sand-dim">{{ $data['text'] }}</p>
                        @endif
                        <x-button :href="$data['url']" :external="str_starts_with((string) $data['url'], 'http') && ! str_starts_with((string) $data['url'], url('/'))">{{ $data['label'] ?? 'Find out more' }}</x-button>
                    </div>
                @endif
                @break
        @endswitch
    @endforeach
</div>
