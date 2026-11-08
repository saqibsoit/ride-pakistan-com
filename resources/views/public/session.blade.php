{{-- /sessions/{product}: product detail (inspiration 01, in our brand). --}}
@php
    use App\Http\Controllers\Public\BookController;
    use App\Support\Money;
    $settings = app(\App\Support\Settings::class);
    $bookUrl = BookController::urlFor($product);
    $unit = $product->isPackage() ? 'for '.$product->sessions_count.' sessions' : 'per rider';
    $tz = (string) config('app.display_timezone');
    $priceSentence = Money::format($product->price_minor).' '.$unit.($product->isPackage()
        ? ' ('.Money::format($product->perSessionMinor()).' per session), valid '.$product->validityWeeks().' weeks from confirmation'
        : '');
    $goodToKnow = array_values(array_filter([
        ...($product->good_to_know ?? []),
        $minAge !== null ? "Minimum rider age: {$minAge}." : null,
        ...$policies,
    ]));
@endphp

<x-layouts.app :seo="$seo">
    <div class="wrap pt-12 md:pt-16">
        <x-breadcrumb :items="[['label' => 'Home', 'url' => route('home')], ['label' => 'Experiences', 'url' => route('experiences')], ['label' => $product->name]]" class="mb-10" />
    </div>

    <div class="wrap grid gap-12 pb-section lg:grid-cols-3 lg:gap-16">
        <div class="flex min-w-0 flex-col gap-16 lg:col-span-2">
            <header class="flex flex-col gap-8">
                <div class="flex flex-wrap items-center gap-3">
                    <x-badge>{{ $product->category->label() }}</x-badge>
                    @if ($product->is_featured)
                        <x-badge variant="featured">Featured</x-badge>
                    @endif
                </div>
                <h1 class="text-display-xl">{{ $product->name }}</h1>
                <x-answer>
                    {{ $product->short_description }}
                    It costs {{ $priceSentence }}.
                    Sessions run {{ $facts->scheduleText() }} in {{ $facts->location() }}.
                </x-answer>
            </header>

            <section aria-labelledby="facts-title" class="grid items-start gap-8 md:grid-cols-2">
                <h2 id="facts-title" class="sr-only">Key facts</h2>
                <dl class="flex flex-col gap-6 border-l-3 border-saddle pl-6">
                    <div>
                        <dt class="font-display text-display-xs font-extrabold">When</dt>
                        <dd class="mt-1 text-sand-dim">{{ ucfirst($facts->scheduleText()) }}</dd>
                    </div>
                    <div>
                        <dt class="font-display text-display-xs font-extrabold">Where</dt>
                        <dd class="mt-1 text-sand-dim">{{ $facts->location() }}. Exact pin sent once your booking is confirmed.</dd>
                    </div>
                    <div>
                        <dt class="font-display text-display-xs font-extrabold">Duration</dt>
                        <dd class="mt-1 text-sand-dim">{{ $product->isPackage() ? $product->sessions_count.' sessions of '.$product->durationLabel() : $product->durationLabel() }}</dd>
                    </div>
                    @if ($facts->groupSize($product))
                        <div>
                            <dt class="font-display text-display-xs font-extrabold">Group</dt>
                            <dd class="mt-1 text-sand-dim">{{ $facts->groupSize($product) }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="font-display text-display-xs font-extrabold">Level</dt>
                        <dd class="mt-1 text-sand-dim">No experience needed</dd>
                    </div>
                </dl>
                <x-img :src="$product->cover_image ?? 'ride.jpg'" :alt="(string) $product->cover_image_alt" sizes="(min-width: 64rem) 25rem, (min-width: 48rem) 50vw, 100vw" priority class="aspect-4/3 w-full object-cover" />
            </section>

            @if ($product->long_description || $product->who_for)
                <section aria-labelledby="about-title" class="flex flex-col gap-5">
                    <h2 id="about-title" class="text-display-lg">About this session.</h2>
                    @if ($product->long_description)
                        <div class="prose-content text-sand-dim">{!! \App\Support\Markdown::toHtml($product->long_description) !!}</div>
                    @endif
                    @if ($product->who_for)
                        <p class="max-w-prose"><strong class="font-semibold">Who it’s for:</strong> <span class="text-sand-dim">{{ $product->who_for }}</span></p>
                    @endif
                </section>
            @endif

            <section aria-labelledby="timeline-title">
                <h2 id="timeline-title" class="mb-8 text-display-lg">The session.</h2>
                <x-step-list>
                    <x-step title="Bring him in">Lead your horse in from the paddock and learn how to read his mood.</x-step>
                    <x-step title="Groom">Brush him down and check his hooves. This is where the bond starts.</x-step>
                    <x-step title="Tack up">Fit the saddle and bridle yourself, with your trainer checking every buckle.</x-step>
                    <x-step title="Ride">Mostly trail riding in the open; arena work on request.</x-step>
                    <x-step title="Cool down">Untack, then massage and stretch your horse.</x-step>
                </x-step-list>
            </section>

            @if (! empty($product->what_to_bring))
                <section aria-labelledby="bring-title">
                    <h2 id="bring-title" class="mb-6 text-display-lg">What to bring.</h2>
                    <ul class="tick-list max-w-prose">
                        @foreach ($product->what_to_bring as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (! empty($product->whats_included))
                <section aria-labelledby="included-title">
                    <h2 id="included-title" class="mb-6 text-display-lg">Your booking includes.</h2>
                    <ul class="tick-list max-w-prose">
                        @foreach ($product->whats_included as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($goodToKnow !== [])
                <section aria-labelledby="know-title">
                    <h2 id="know-title" class="mb-6 text-display-lg">Good to know.</h2>
                    <ul class="tick-list max-w-prose">
                        @foreach ($goodToKnow as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-4 text-sm text-sand-dim"><a href="{{ route('prices') }}#policies" class="underline underline-offset-4">All riding rules and booking policies</a></p>
                </section>
            @endif

            @if (! empty($product->gallery))
                <section aria-labelledby="gallery-title">
                    <h2 id="gallery-title" class="mb-6 text-display-lg">Photos.</h2>
                    <ul class="grid grid-cols-2 gap-3 md:grid-cols-3" role="list">
                        @foreach ($product->gallery as $photo)
                            @if (! empty($photo['image']))
                                <li>
                                    <x-img :src="$photo['image']" :alt="(string) ($photo['alt'] ?? '')" sizes="(min-width: 48rem) 20vw, 50vw" class="aspect-4/3 w-full object-cover" />
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Approved reviews of this product (milestone 10d). --}}
            @if ($reviews->isNotEmpty())
                <section aria-labelledby="reviews-title">
                    <h2 id="reviews-title" class="text-display-lg">What riders say.</h2>
                    <p class="mt-2 text-sm text-sand-dim">{{ number_format($reviewSummary['average'], 1) }} out of 5 from {{ $reviewSummary['count'] }} {{ $reviewSummary['count'] === 1 ? 'review' : 'reviews' }} by riders who took this session.</p>
                    <x-review-list :reviews="$reviews" class="mt-6 md:grid-cols-2 lg:grid-cols-2" />
                </section>
            @endif

            <x-booking.widget :product="$product" :slots="$slots" />

            @if ($faqs->isNotEmpty())
                <section aria-labelledby="faq-title">
                    <h2 id="faq-title" class="mb-8 text-display-lg">Questions.</h2>
                    @foreach ($faqs as $faq)
                        <x-faq-item :question="$faq->question" group="product-faq">{{ $faq->answer }}</x-faq-item>
                    @endforeach
                </section>
            @endif
        </div>

        <div>
            <x-booking.price-box :from-minor="$product->price_minor" :unit="$unit" :book-href="$bookUrl" label="Book now" :note="$product->isPackage() ? 'Valid '.$product->validityWeeks().' weeks from confirmation.' : null">
                <p class="flex items-center gap-2 text-sm text-sand-dim"><x-glyph name="clock" class="size-4" />{{ $product->durationLabel() }} per session</p>
                <p class="flex items-center gap-2 text-sm text-sand-dim"><x-glyph name="pin" class="size-4" />{{ $facts->location() }}</p>
                @if ($facts->groupSize($product))
                    <p class="flex items-center gap-2 text-sm text-sand-dim"><x-glyph name="users" class="size-4" />{{ $facts->groupSize($product) }}</p>
                @endif
                @if ($next = $slots->first())
                    <div class="flex flex-col gap-1 border-t border-line pt-3">
                        <p class="text-sm">Next: <time datetime="{{ $next->starts_at->toIso8601String() }}">{{ $next->localStartsAt()->format('D j M, g:i a') }}</time></p>
                        @if ($product->type !== \App\Enums\ProductType::Private)
                            <x-seats-left :seats="$next->seatsFree()" />
                        @endif
                    </div>
                @endif
            </x-booking.price-box>
        </div>
    </div>

    @if ($others->isNotEmpty())
        <section class="border-t border-line py-section" aria-labelledby="more-title">
            <div class="wrap">
                <h2 id="more-title" class="mb-10 text-display-lg">More ways to ride.</h2>
                <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($others->take(3) as $other)
                        <li class="flex">
                            <x-experience-card
                                class="w-full"
                                :title="$other->name"
                                :href="route('sessions.show', $other)"
                                :book-href="BookController::urlFor($other)"
                                :image="$other->cover_image ?? 'ride.jpg'"
                                :image-alt="(string) $other->cover_image_alt"
                                :category="$other->category->label()"
                                :description="$other->short_description"
                                :price-minor="$other->price_minor"
                                :price-unit="$other->isPackage() ? 'for '.$other->sessions_count.' sessions' : 'per rider'"
                                :featured="$other->is_featured"
                            />
                        </li>
                    @endforeach
                </ul>
                <p class="mt-8"><a href="{{ route('expeditions') }}" class="inline-flex items-center gap-2 font-semibold underline underline-offset-4">Ready for the mountains? See our expeditions <x-glyph name="arrow-right" class="size-4" /></a></p>
            </div>
        </section>
    @endif

    <x-booking.bottom-bar :from-minor="$product->price_minor" :unit="$unit" :book-href="$bookUrl" />
</x-layouts.app>
