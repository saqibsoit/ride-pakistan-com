{{-- CMS page: trainer profile, expeditions, legal pages. --}}
<x-layouts.app :seo="$seo">
    <x-page-header
        :title="$page->title"
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => $page->title]]"
        :answer="$page->answer"
    />

    @if ($isTrainer)
        <div class="wrap grid items-start gap-12 pb-section md:grid-cols-5 md:gap-16">
            <x-img src="tent.jpg" alt="A horse in a blanket seen through a tent opening at dusk" sizes="(min-width: 48rem) 28rem, 100vw" eager class="aspect-3/4 w-full object-cover md:col-span-2" />
            <x-blocks :blocks="$page->body" class="md:col-span-3" />
        </div>
    @else
        <div class="wrap pb-section">
            <x-blocks :blocks="$page->body" />
        </div>
    @endif
</x-layouts.app>
