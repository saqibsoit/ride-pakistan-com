{{-- /events/{slug}: an event page (inspiration 01, in our brand; decided 2026-11-22). --}}
@php
    use App\Http\Controllers\Public\EventController;
    use App\Support\Money;
    $bookUrl = EventController::bookUrl($event);
    $deposit = $event->fromDepositMinor();
    $bookable = $availability->bookable();
    $first = $availability->dates->first();
    $sameDay = $first !== null && $first->localStartsAt()->isSameDay($first->localEndsAt());
    $priceSentence = Money::format($event->fromPriceMinor()).' per person'.($event->takesDeposit() ? ', with a '.$event->deposit_percent.'% non-refundable deposit to book' : '');
    $soldOut = $availability->isSoldOut();
@endphp

<x-layouts.app :seo="$seo">
    <div class="wrap pt-12 md:pt-16">
        <x-breadcrumb :items="[['label' => 'Home', 'url' => route('home')], ['label' => 'Events', 'url' => route('events.index')], ['label' => $event->title]]" class="mb-10" />
    </div>

    <div class="wrap grid gap-12 pb-section lg:grid-cols-3 lg:gap-16">
        <div class="flex min-w-0 flex-col gap-16 lg:col-span-2">
            <header class="flex flex-col gap-8">
                <div class="flex flex-wrap items-center gap-3">
                    @if ($event->category)
                        <x-badge>{{ $event->category->name }}</x-badge>
                    @endif
                    @if ($event->is_featured)
                        <x-badge variant="featured">Featured</x-badge>
                    @endif
                    @if ($availability->badge())
                        <x-badge :variant="$soldOut ? 'neutral' : 'selling-fast'">{{ $availability->badge() }}</x-badge>
                    @endif
                </div>
                <h1 class="text-display-xl">{{ $event->title }}</h1>
                <x-answer>
                    {{ $event->summary }}
                    It costs {{ $priceSentence }}.
                    @if ($first)
                        {{ $availability->dates->count() === 1 ? 'The date is' : 'Dates:' }} {{ $availability->dates->map(fn ($d) => $d->localStartsAt()->format('D j M Y'))->implode(', ') }}, at {{ $event->location_name ?: $facts->venueWithLocation() }}.
                    @endif
                </x-answer>
            </header>

            <section aria-labelledby="facts-title" class="grid items-start gap-8 md:grid-cols-2">
                <h2 id="facts-title" class="sr-only">Key facts</h2>
                <dl class="flex flex-col gap-6 border-l-3 border-saddle pl-6">
                    <div>
                        <dt class="font-display text-display-xs font-extrabold">When</dt>
                        <dd class="mt-1 text-sand-dim">
                            @forelse ($availability->dates as $date)
                                <span class="block"><time datetime="{{ $date->starts_at->toIso8601String() }}">{{ $date->dateRangeLabel() }}</time></span>
                            @empty
                                No dates to come.
                            @endforelse
                        </dd>
                    </div>
                    <div>
                        <dt class="font-display text-display-xs font-extrabold">Where</dt>
                        <dd class="mt-1 text-sand-dim">{{ $event->location_name ?: $facts->venueWithLocation() }}.@if ($facts->mapUrl() !== null) <a href="#where-title" class="underline underline-offset-4">Map and directions</a>@endif</dd>
                    </div>
                    @if ($first && $sameDay)
                        <div>
                            <dt class="font-display text-display-xs font-extrabold">Duration</dt>
                            <dd class="mt-1 text-sand-dim">{{ $first->localStartsAt()->format('g:i a') }} to {{ $first->localEndsAt()->format('g:i a') }}</dd>
                        </div>
                    @endif
                    @foreach ($event->blocksOf('requirements') as $req)
                        @foreach (['level' => 'Level', 'age' => 'Age', 'fitness' => 'Fitness'] as $key => $label)
                            @if (! empty($req[$key]))
                                <div>
                                    <dt class="font-display text-display-xs font-extrabold">{{ $label }}</dt>
                                    <dd class="mt-1 text-sand-dim">{{ $req[$key] }}</dd>
                                </div>
                            @endif
                        @endforeach
                    @endforeach
                </dl>
                <x-img :src="$event->cover_image ?? 'camp.jpg'" :alt="(string) ($event->cover_image_alt ?? '')" sizes="(min-width: 64rem) 25rem, (min-width: 48rem) 50vw, 100vw" priority class="aspect-4/3 w-full object-cover" />
            </section>

            @if ($event->description)
                <section aria-labelledby="about-title" class="flex flex-col gap-5">
                    <h2 id="about-title" class="text-display-lg">About this event.</h2>
                    <div class="prose-content text-sand-dim">{!! \App\Support\Markdown::toHtml($event->description) !!}</div>
                </section>
            @endif

            @foreach ($event->blocks ?? [] as $i => $block)
                @include('public.events.block', ['block' => $block, 'i' => $i])
            @endforeach

            <section aria-labelledby="dates-title" id="dates">
                <h2 id="dates-title" class="mb-6 text-display-lg">Dates.</h2>
                @if ($availability->dates->isEmpty())
                    <p class="text-sand-dim">There are no dates to come. <a href="{{ route('events.index') }}" class="underline underline-offset-4">See other events</a>.</p>
                @else
                    <ul class="flex flex-col gap-3" role="list">
                        @foreach ($availability->dates as $date)
                            @php($open = $bookable->contains('id', $date->id))
                            <li class="flex flex-wrap items-center justify-between gap-4 rounded-sm border border-line bg-earth-2 p-5">
                                <div class="flex flex-col gap-1">
                                    <p class="font-semibold"><time datetime="{{ $date->starts_at->toIso8601String() }}">{{ $date->dateRangeLabel() }}</time></p>
                                    @if ($open)
                                        <x-seats-left :seats="$date->seatsFree()" :threshold="$availability->threshold" />
                                    @else
                                        <p class="text-sm font-semibold text-sand-dim">{{ $date->status === \App\Enums\SlotStatus::Closed ? 'Not taking bookings' : 'Bookings closed' }}</p>
                                    @endif
                                </div>
                                @if ($open && $date->seatsFree() > 0)
                                    <x-button :href="EventController::bookUrl($event, $date->id)" size="sm">Book this date<span class="sr-only">: {{ $date->dateRangeLabel() }}</span></x-button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="terms-title">
                <h2 id="terms-title" class="mb-6 text-display-lg">Payment and cancellations.</h2>
                <ul class="tick-list max-w-prose">
                    @if ($event->takesDeposit())
                        <li>Book with a {{ $event->deposit_percent }}% deposit ({{ Money::format((int) $deposit) }} per person at the lowest price), by card or bank transfer. The balance is {{ $event->balance_due === \App\Enums\BalanceDue::OnTheDay ? 'paid on the day, at the venue or online before' : 'paid online before the deadline' }}.</li>
                    @else
                        <li>Pay in full when you book, by card or bank transfer.</li>
                    @endif
                    @foreach ($terms as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                @if (\App\Models\Page::query()->where('slug', 'refund-policy')->published()->exists())
                    <p class="mt-4 text-sm text-sand-dim"><a href="{{ route('legal.refund-policy') }}" class="underline underline-offset-4">Our cancellation and refund policy</a></p>
                @endif
            </section>

            @if ($facts->mapUrl() !== null)
                <section aria-labelledby="where-title">
                    <x-venue-map />
                </section>
            @endif

            @if (! empty($event->gallery))
                <section aria-labelledby="gallery-title">
                    <h2 id="gallery-title" class="mb-6 text-display-lg">Photos.</h2>
                    <ul class="grid grid-cols-2 gap-3 md:grid-cols-3" role="list">
                        @foreach ($event->gallery as $photo)
                            @if (! empty($photo['image']))
                                <li><x-img :src="$photo['image']" :alt="(string) ($photo['alt'] ?? '')" sizes="(min-width: 48rem) 20vw, 50vw" class="aspect-4/3 w-full object-cover" /></li>
                            @endif
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <div>
            <x-booking.price-box :from-minor="$event->fromPriceMinor()" unit="per person" :book-href="$soldOut || $bookable->isEmpty() ? '#dates' : $bookUrl" :label="$soldOut ? 'Fully booked' : 'Book'" :note="$event->takesDeposit() ? Money::format((int) $deposit).' deposit to book, non-refundable.' : null">
                @if ($event->takesDeposit())
                    <p class="text-sm font-semibold">{{ Money::format((int) $deposit) }} deposit</p>
                @endif
                <p class="flex items-center gap-2 text-sm text-sand-dim"><x-glyph name="pin" class="size-4" />{{ $event->location_name ?: $facts->location() }}</p>
                @foreach ($availability->dates as $date)
                    <div class="flex flex-col gap-1 border-t border-line pt-3">
                        <p class="text-sm"><time datetime="{{ $date->starts_at->toIso8601String() }}">{{ $date->localStartsAt()->format('D j M, g:i a') }}</time></p>
                        @if ($bookable->contains('id', $date->id))
                            <x-seats-left :seats="$date->seatsFree()" :threshold="$availability->threshold" />
                        @else
                            <p class="text-sm text-sand-dim">Bookings closed</p>
                        @endif
                    </div>
                @endforeach
            </x-booking.price-box>
        </div>
    </div>

    @if ($others->isNotEmpty())
        <section class="border-t border-line py-section" aria-labelledby="more-title">
            <div class="wrap">
                <h2 id="more-title" class="mb-10 text-display-lg">More events.</h2>
                <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($others as $other)
                        <li class="flex">
                            <x-experience-card class="w-full" :title="$other->title" :href="route('events.show', $other)" :book-href="EventController::bookUrl($other)"
                                :image="$other->cover_image ?? 'camp.jpg'" :image-alt="(string) $other->cover_image_alt" :category="$other->category?->name"
                                :description="$other->summary" :price-minor="$other->fromPriceMinor()" price-unit="per person" :featured="$other->is_featured" />
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    @unless ($soldOut || $bookable->isEmpty())
        <x-booking.bottom-bar :from-minor="$event->fromPriceMinor()" unit="per person" :book-href="$bookUrl" label="Book" />
    @endunless
</x-layouts.app>
