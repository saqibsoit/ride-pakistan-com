{{--
    Sticky site header. overlay: transparent over a hero, solid once scrolled
    (or when the mobile menu is open). Behaviour lives in
    resources/js/components/site-header.js.
--}}
@props(['overlay' => false])

@php
    $links = [
        ['label' => 'Experiences', 'url' => url('/experiences'), 'match' => 'experiences*'],
        ['label' => 'Schedule', 'url' => url('/schedule'), 'match' => 'schedule*'],
        ['label' => 'Prices', 'url' => url('/prices'), 'match' => 'prices*'],
        ['label' => 'Your trainer', 'url' => url('/trainer/omar-saleem'), 'match' => 'trainer*'],
        ['label' => 'FAQ', 'url' => url('/faq'), 'match' => 'faq*'],
    ];
    $bookUrl = url('/book');
@endphp

<header
    x-data="siteHeader"
    x-on:keydown.escape="onEscape"
    x-on:focusout="onFocusOut"
    @if ($overlay) x-bind:class="overlayClass" @endif
    {{ $attributes->class([
        'site-header top-0 z-40 w-full transition-colors duration-300',
        'fixed inset-x-0' => $overlay,
        'sticky border-b border-line bg-earth/95' => ! $overlay,
    ]) }}
>
    <div class="wrap flex h-header items-center justify-between gap-4">
        <a href="{{ url('/') }}" class="flex items-center gap-3.5 font-display text-display-xs font-extrabold no-underline">
            <x-img src="logo.png" alt="" sizes="68px" eager class="h-10 w-auto" />
            <span class="max-sm:sr-only">Ride Pakistan</span>
            <span class="sr-only">, home</span>
        </a>

        <nav aria-label="Main" class="hidden items-center gap-8 lg:flex">
            @foreach ($links as $link)
                <a
                    href="{{ $link['url'] }}"
                    @if (request()->is($link['match'])) aria-current="page" @endif
                    class="text-sm text-sand-dim no-underline transition-colors hover:text-sand current:text-sand"
                >{{ $link['label'] }}</a>
            @endforeach
            <x-button :href="$bookUrl" size="sm">Book a session</x-button>
        </nav>

        <div class="flex items-center gap-2 lg:hidden">
            <x-button :href="$bookUrl" size="sm">Book a session</x-button>
            <button
                type="button"
                x-ref="toggle"
                x-on:click="toggle"
                x-bind:aria-expanded="expanded"
                aria-expanded="false"
                aria-controls="mobile-menu"
                class="inline-flex size-11 items-center justify-center rounded-sm text-sand"
            >
                <span x-show="closed"><x-glyph name="menu" class="size-6" /></span>
                <span x-show="open" x-cloak><x-glyph name="close" class="size-6" /></span>
                <span class="sr-only" x-text="toggleLabel">Open menu</span>
            </button>
        </div>
    </div>

    <div
        id="mobile-menu"
        x-ref="menu"
        x-show="open"
        x-cloak
        class="fixed inset-x-0 top-header bottom-0 overflow-y-auto border-t border-line bg-earth lg:hidden"
    >
        <nav aria-label="Main" class="wrap flex flex-col py-6">
            @foreach ($links as $link)
                <a
                    href="{{ $link['url'] }}"
                    @if (request()->is($link['match'])) aria-current="page" @endif
                    class="border-b border-line py-4 font-display text-display-sm font-extrabold no-underline current:underline underline-offset-8"
                >{{ $link['label'] }}</a>
            @endforeach
            <a href="{{ config('site.instagram_dm_url') }}" target="_blank" rel="noopener" class="mt-6 text-sand-dim underline underline-offset-4">
                Message us on Instagram<span class="sr-only"> (opens in a new tab)</span>
            </a>
        </nav>
    </div>
</header>
