{{--
    Catalog card (inspiration 03, in our brand).

    <x-experience-card title="Single session" href="/sessions/single" book-href="/book?product=single"
        image="ride.jpg" image-alt="..." category="Lessons" :price-minor="1000000" price-unit="per rider"
        description="..." duration="2 hours" group-size="Up to 6 riders" location="Islamabad" featured />
--}}
@props([
    'title',
    'href',
    'bookHref',
    'image',
    'imageAlt' => '',
    'category' => null,
    'description' => null,
    'priceMinor' => null,
    'priceUnit' => 'per rider',
    'featured' => false,
    'duration' => null,
    'groupSize' => null,
    'location' => null,
    'videoHref' => null,
    'level' => 3,
])

@php($tag = 'h'.max(2, min(6, (int) $level)))

<article {{ $attributes->class('flex flex-col overflow-hidden rounded-sm border border-line bg-earth-2') }}>
    <div class="relative">
        <x-img
            :src="$image"
            :alt="$imageAlt"
            sizes="(min-width: 64rem) 24rem, (min-width: 40rem) 50vw, 100vw"
            class="aspect-4/3 w-full object-cover"
        />
        @if ($featured)
            <x-badge variant="featured" class="absolute top-4 left-4">Featured</x-badge>
        @endif
        @if ($priceMinor !== null)
            <x-badge variant="price" class="absolute bottom-4 left-4">
                <x-money :minor="$priceMinor" class="font-bold" />@if ($priceUnit)&nbsp;{{ $priceUnit }}@endif
            </x-badge>
        @endif
    </div>

    <div class="flex flex-1 flex-col gap-3 p-6">
        @if ($category)
            <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">{{ $category }}</p>
        @endif
        <{{ $tag }} class="text-display-sm">{{ $title }}</{{ $tag }}>
        @if ($description)
            <p class="line-clamp-2 text-sand-dim">{{ $description }}</p>
        @endif

        @if ($duration || $groupSize || $location)
            <dl class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-sand-dim">
                @if ($duration)
                    <div class="flex items-center gap-1.5"><x-glyph name="clock" class="size-4" /><dt class="sr-only">Duration</dt><dd>{{ $duration }}</dd></div>
                @endif
                @if ($groupSize)
                    <div class="flex items-center gap-1.5"><x-glyph name="users" class="size-4" /><dt class="sr-only">Group size</dt><dd>{{ $groupSize }}</dd></div>
                @endif
                @if ($location)
                    <div class="flex items-center gap-1.5"><x-glyph name="pin" class="size-4" /><dt class="sr-only">Location</dt><dd>{{ $location }}</dd></div>
                @endif
            </dl>
        @endif

        <div class="mt-auto flex flex-wrap gap-3 pt-3">
            <x-button :href="$href" variant="ghost" size="sm">Details<span class="sr-only"> about {{ $title }}</span></x-button>
            <x-button :href="$bookHref" size="sm">Book now<span class="sr-only">: {{ $title }}</span></x-button>
            @if ($videoHref)
                <x-button :href="$videoHref" variant="ghost" size="sm" external><x-glyph name="play" class="size-4" />Video<span class="sr-only"> of {{ $title }}</span></x-button>
            @endif
        </div>
    </div>
</article>
