{{-- /guides/{slug}: one guide (Article schema). --}}
<x-layouts.app :seo="$seo">
    <article>
        <header class="wrap pt-12 pb-12 md:pt-16">
            <x-breadcrumb :items="[['label' => 'Home', 'url' => route('home')], ['label' => 'Guides', 'url' => route('guides.index')], ['label' => $post->title]]" class="mb-10" />
            <h1 class="max-w-narrow text-display-xl">{{ $post->title }}</h1>
            @if ($post->excerpt)
                <x-answer class="mt-8">{{ $post->excerpt }}</x-answer>
            @endif
            <p class="mt-6 text-sm text-sand-dim">
                By <a href="{{ route('trainer') }}" class="underline underline-offset-4">{{ $post->author_name }}</a>
                · <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->setTimezone((string) config('app.display_timezone'))->format('j F Y') }}</time>
            </p>
        </header>

        @if ($post->cover_image)
            <div class="wrap mb-14">
                <x-img :src="$post->cover_image" :alt="(string) $post->cover_image_alt" sizes="(min-width: 75rem) 75rem, 100vw" eager class="aspect-video w-full object-cover" />
            </div>
        @endif

        <div class="wrap pb-section">
            <x-blocks :blocks="$post->body" />

            <aside class="mt-16 max-w-prose rounded-sm border border-line bg-earth-2 p-7" aria-labelledby="next-title">
                <h2 id="next-title" class="text-display-xs">Ready to ride?</h2>
                <p class="mt-3 text-sand-dim">Sessions run {{ $facts->scheduleText() }} in {{ $facts->location() }}. No experience needed.</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <x-button :href="route('book')" size="sm">Book a session</x-button>
                    <x-button :href="route('prices')" variant="ghost" size="sm">See prices</x-button>
                </div>
            </aside>
        </div>
    </article>

    @if ($more->isNotEmpty())
        <section class="border-t border-line py-section" aria-labelledby="more-title">
            <div class="wrap">
                <h2 id="more-title" class="mb-8 text-display-md">More guides.</h2>
                <ul class="flex flex-col gap-4">
                    @foreach ($more as $other)
                        <li><a href="{{ route('guides.show', $other) }}" class="font-display text-display-xs font-extrabold underline-offset-4 hover:underline">{{ $other->title }}</a></li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif
</x-layouts.app>
