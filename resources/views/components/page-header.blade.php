{{--
    Inner page header: breadcrumbs, the page's one h1, and the answer block.

    <x-page-header title="Prices" :breadcrumbs="[...]" answer="...">
        optional extra content (buttons)
    </x-page-header>
--}}
@props([
    'title',
    'breadcrumbs' => [],
    'answer' => null,
    'eyebrow' => null,
])

<header {{ $attributes->class('wrap pt-12 pb-14 md:pt-16') }}>
    @if ($breadcrumbs !== [])
        <x-breadcrumb :items="$breadcrumbs" class="mb-10" />
    @endif
    @if ($eyebrow)
        <p class="mb-4 text-xs font-semibold uppercase tracking-label text-sand-dim">{{ $eyebrow }}</p>
    @endif
    <h1 class="max-w-narrow text-display-xl">{{ $title }}</h1>
    @if ($answer)
        <x-answer class="mt-8">{{ $answer }}</x-answer>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="mt-9 flex flex-wrap gap-3.5">{{ $slot }}</div>
    @endif
</header>
