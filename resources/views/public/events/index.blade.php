{{-- /events: upcoming events with category filters (decided 2026-11-22). --}}
@php
    use App\Http\Controllers\Public\EventController;
    use App\Models\Slot;
@endphp

<x-layouts.app :seo="$seo">
    <x-page-header
        title="Events."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Events']]"
        :answer="'Day camps, workshops and rides at '.$facts->venueWithLocation().', on dates set in advance. Each event lists what’s included, the programme and the price in Pakistani rupees; book online by card or bank transfer.'"
    />

    <section class="wrap pb-section" aria-labelledby="events-title">
        <h2 id="events-title" class="sr-only">Upcoming events</h2>

        @if ($categories->count() > 1)
            <x-filter-chips label="Filter events" class="mb-10">
                <x-filter-chip :href="route('events.index')" :active="$active === null">All</x-filter-chip>
                @foreach ($categories as $category)
                    <x-filter-chip :href="route('events.index', ['category' => $category->slug])" :active="$active === $category->slug">{{ $category->name }}</x-filter-chip>
                @endforeach
            </x-filter-chips>
        @endif

        @if ($events->isEmpty())
            <p class="text-sand-dim">No events are open for booking right now. Follow us on Instagram to hear about the next one, or <a href="{{ route('experiences') }}" class="underline underline-offset-4">book a riding session</a>.</p>
        @else
            <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($events as $item)
                    @php($event = $item->event)
                    @php($first = $item->dates->first())
                    <li class="flex">
                        <x-experience-card
                            class="w-full"
                            :title="$event->title"
                            :href="route('events.show', $event)"
                            :book-href="EventController::bookUrl($event)"
                            :image="$event->cover_image ?? 'camp.jpg'"
                            :image-alt="(string) $event->cover_image_alt"
                            :category="$event->category?->name"
                            :description="$event->summary"
                            :price-minor="$event->fromPriceMinor()"
                            price-unit="per person"
                            :featured="$event->is_featured"
                            :duration="$first ? $first->localStartsAt()->format('D j M').($item->dates->count() > 1 ? ' + '.($item->dates->count() - 1).' more' : '') : null"
                            :location="$event->location_name ?: $facts->location()"
                            :video-href="$event->video_url"
                            :status="$item->badge()"
                        />
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
