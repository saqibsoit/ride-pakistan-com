{{--
    Home page events (decided 2026-11-22), shown right after the hero while
    an event has a date to come. The first event is the spotlight (photo,
    dates with availability, price and deposit, Book); the others follow as
    cards. Customers never see seat counts (<x-seats-left>).

    <x-event-spotlight :events="$events" />  (a collection of EventAvailability)
--}}
@props(['events'])

@php
    use App\Http\Controllers\Public\EventController;
    use App\Support\Money;

    $lead = $events->first();
    $event = $lead->event;
    $bookable = $lead->bookable();
    $soldOut = $lead->isSoldOut();
    $deposit = $event->fromDepositMinor();
    $from = $event->fromPriceMinor();
    $rest = $events->slice(1);
@endphp

<section {{ $attributes->class('wrap pt-section') }} aria-labelledby="events-title">
    <div class="flex flex-wrap items-end justify-between gap-6">
        <x-section-heading id="events-title" title="Upcoming events." size="lg" />
        <a href="{{ route('events.index') }}" class="inline-flex items-center gap-2 font-semibold underline underline-offset-4">All events <x-glyph name="arrow-right" class="size-4" /></a>
    </div>

    <article class="mt-10 grid border border-line bg-earth-2 md:grid-cols-2" aria-labelledby="event-spotlight-title">
        <a href="{{ route('events.show', $event) }}" class="relative block" tabindex="-1" aria-hidden="true">
            <x-img :src="$event->cover_image ?? 'camp.jpg'" :alt="(string) $event->cover_image_alt" sizes="(min-width: 48rem) 50vw, 100vw" class="aspect-4/3 size-full object-cover" />
            @if ($lead->badge() !== null)
                <x-badge :variant="$soldOut ? 'neutral' : 'selling-fast'" class="absolute top-4 left-4">{{ $lead->badge() }}</x-badge>
            @endif
        </a>

        <div class="flex flex-col gap-6 p-6 md:p-10">
            @if ($event->category)
                <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">{{ $event->category->name }}</p>
            @endif
            <h3 id="event-spotlight-title" class="text-display-md">
                <a href="{{ route('events.show', $event) }}" class="no-underline hover:underline underline-offset-8">{{ $event->title }}</a>
            </h3>
            @if ($event->summary)
                <p class="max-w-prose text-lede text-sand-dim">{{ $event->summary }}</p>
            @endif

            <ul class="flex flex-col gap-3 border-l-3 border-saddle pl-6" aria-label="Dates">
                @foreach ($lead->dates as $date)
                    <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                        <time datetime="{{ $date->starts_at->toIso8601String() }}" class="font-semibold">{{ $date->dateRangeLabel() }}</time>
                        @if ($bookable->contains('id', $date->id))
                            <x-seats-left :seats="$date->seatsFree()" :threshold="$lead->threshold" />
                        @else
                            <span class="text-sm text-sand-dim">{{ $date->seatsFree() === 0 ? 'Fully booked' : 'Closed for booking' }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($from !== null)
                <p>
                    <span class="font-display text-display-xs font-extrabold">From {{ Money::format($from) }}</span>
                    <span class="text-sand-dim">per person</span>
                    @if ($event->takesDeposit() && $deposit !== null)
                        <span class="block text-sm text-sand-dim">{{ Money::format($deposit) }} non-refundable deposit to book.</span>
                    @endif
                </p>
            @endif

            <div class="mt-auto flex flex-wrap gap-3.5">
                @if ($bookable->isNotEmpty())
                    <x-button :href="EventController::bookUrl($event)">Book your place<span class="sr-only">: {{ $event->title }}</span></x-button>
                @endif
                <x-button :href="route('events.show', $event)" variant="ghost">Event details<span class="sr-only">: {{ $event->title }}</span></x-button>
            </div>
        </div>
    </article>

    @if ($rest->isNotEmpty())
        <ul class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($rest as $item)
                @php($first = $item->dates->first())
                <li class="flex">
                    <x-experience-card class="w-full" :title="$item->event->title" :href="route('events.show', $item->event)"
                        :book-href="EventController::bookUrl($item->event)"
                        :image="$item->event->cover_image ?? 'camp.jpg'" :image-alt="(string) $item->event->cover_image_alt"
                        :category="$item->event->category?->name" :description="$item->event->summary"
                        :price-minor="$item->event->fromPriceMinor()" price-unit="per person" :featured="$item->event->is_featured"
                        :duration="$first ? $first->localStartsAt()->format('D j M').($item->dates->count() > 1 ? ' + '.($item->dates->count() - 1).' more' : '') : null"
                        :status="$item->badge()" />
                </li>
            @endforeach
        </ul>
    @endif
</section>
