{{-- /guides: published guides. --}}
<x-layouts.app :seo="$seo">
    <x-page-header
        title="Guides."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Guides']]"
        :answer="'Straight answers for new riders in '.$facts->location().': how beginner lessons work, what to wear, staying safe around horses, and choosing between private and group sessions. Written with trainer '.$facts->trainer().'.'"
    />

    <section class="wrap pb-section" aria-label="All guides">
        @if ($posts->isEmpty())
            <p class="max-w-prose text-sand-dim">Our first guides are being written. Meanwhile, the <a href="{{ route('faq') }}" class="underline underline-offset-4">FAQ</a> answers the most common questions.</p>
        @else
            <ul class="grid gap-10 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    <li>
                        <article class="relative flex flex-col gap-4">
                            <x-img :src="$post->cover_image ?? 'meet.jpg'" :alt="(string) $post->cover_image_alt" sizes="(min-width: 64rem) 24rem, (min-width: 48rem) 50vw, 100vw" class="aspect-3/2 w-full object-cover" />
                            <h2 class="text-display-sm">
                                <a href="{{ route('guides.show', $post) }}" class="after:absolute after:inset-0 hover:underline">{{ $post->title }}</a>
                            </h2>
                            @if ($post->excerpt)
                                <p class="text-sand-dim">{{ $post->excerpt }}</p>
                            @endif
                            <p class="text-sm text-sand-dim">
                                <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->setTimezone((string) config('app.display_timezone'))->format('j F Y') }}</time>
                            </p>
                        </article>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
