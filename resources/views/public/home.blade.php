{{-- Home: same story order as docs/reference/index.html, plus "This weekend". --}}
@php
    use App\Http\Controllers\Public\BookController;
    $times = $facts->timeRanges();
    $from = $facts->fromPriceMinor();
    $tz = (string) config('app.display_timezone');
    $answer = config('app.name').' runs weekend horse riding sessions in '.$facts->location().', '.$facts->scheduleText()
        .', with trainer '.$facts->trainer().'. '
        .($from !== null ? 'A '.$facts->sessionLengthAdjective().' session costs '.\App\Support\Money::format($from).' per rider, no' : 'No')
        .' experience is needed, and 8 and 16-class packages take beginners to confident trail riders.';
@endphp

<x-layouts.app :seo="$seo" overlay-header>
    <x-hero
        image="hero.jpg"
        image-alt="A rider resting his forehead against a white horse"
        :lines="['Never touched', 'a horse?', 'Perfect.']"
        lede="Weekend horsemanship and trail riding in {{ $facts->location() }}. {{ $facts->sessionLength() }} with a horse, from first hello to your first ride, with trainer {{ $facts->trainer() }}."
    >
        <x-button :href="route('book')">Book a session</x-button>
        <x-button :href="route('prices')" variant="ghost">See sessions and prices</x-button>
    </x-hero>

    <x-fact-strip label="Session facts">
        <x-fact :value="$facts->daysShort()" label="Every weekend" />
        @if ($times !== [])
            <x-fact :value="$times[0]" :label="count($times) > 1 ? 'or '.implode(' or ', array_slice($times, 1)) : 'Asia/Karachi time'" />
        @endif
        <x-fact :value="$facts->sessionLength()" label="Per session" />
        <x-fact :value="$facts->location()" label="No experience needed" />
    </x-fact-strip>

    <section class="wrap pt-section" aria-label="In short">
        <x-answer>{{ $answer }}</x-answer>
    </section>

    {{-- This weekend: live slots (rule and manual) with seats left. --}}
    <section class="wrap pt-section" aria-labelledby="weekend-title">
        <div class="flex flex-wrap items-end justify-between gap-6">
            <x-section-heading id="weekend-title" title="This weekend." size="lg" />
            <a href="{{ route('schedule') }}" class="inline-flex items-center gap-2 font-semibold underline underline-offset-4">Full schedule <x-glyph name="arrow-right" class="size-4" /></a>
        </div>
        @if ($weekend->isEmpty())
            <p class="mt-8 text-sand-dim">No more sessions this weekend. <a href="{{ route('schedule') }}" class="underline underline-offset-4">See next week’s times</a>.</p>
        @else
            <ul class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($weekend as $slot)
                    @php($open = $slot->status === \App\Enums\SlotStatus::Open)
                    <li>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-label text-sand-dim">{{ $slot->localStartsAt()->format('l j M') }}</p>
                        <x-slot-card
                            :starts-at="$slot->starts_at"
                            :ends-at="$slot->ends_at"
                            :product="$slot->isPrivatelyBooked() ? 'Private session' : 'Riding session'"
                            :trainer="$facts->trainer()"
                            :seats="$open ? $slot->seatsFree() : null"
                            note="Closed for booking"
                            :href="$open ? route('book', ['slot' => $slot->id]) : null"
                            :waitlist="$open && ! $slot->private_only && ! $slot->isPrivatelyBooked() ? route('account.waitlist.create', $slot) : null"
                        />
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section id="session" class="wrap py-section" aria-labelledby="session-title">
        <div class="mb-16 grid items-end gap-7 md:grid-cols-2 md:gap-16">
            <h2 id="session-title" class="text-display-xl">{{ ucfirst($facts->sessionLength()) }},<br>start to finish.</h2>
            <p class="max-w-prose text-lede text-sand-dim">This isn’t a pony ride around a ring. You’ll do everything a horseman does, from bringing the horse in to settling him down after the ride. That’s how real trust is built.</p>
        </div>
        <div class="grid items-start gap-10 md:grid-cols-2 md:gap-16">
            <figure class="md:sticky md:top-28">
                <x-img src="meet.jpg" alt="A man gently touching a grazing white horse" sizes="(min-width: 48rem) 36rem, 100vw" class="aspect-4/5 w-full object-cover" />
                <figcaption class="mt-3 text-sm text-sand-dim">Photographed on a Ride Pakistan expedition</figcaption>
            </figure>
            <x-step-list>
                <x-step title="Bring him in">Walk out to the paddock and lead your horse in. You’ll learn how to approach a horse, where to stand and how to read his mood.</x-step>
                <x-step title="Groom">Brush him down and check his hooves. It’s the quiet part of the session, and it’s where the bond starts.</x-step>
                <x-step title="Tack up">Fit the saddle and bridle yourself, with your trainer checking every buckle.</x-step>
                <x-step title="Ride">Mostly trail riding, out in the open. If you’d rather work in the arena on your position and control, that’s possible too.</x-step>
                <x-step title="Cool down">Untack, then give your horse a massage and stretch. You finish the way you started: looking after him.</x-step>
            </x-step-list>
        </div>
    </section>

    {{-- Photo band: wide + narrow photo, same height; one photo below md. --}}
    <div class="grid gap-2 md:grid-cols-band" role="presentation" data-photo-band>
        <x-img src="graze.jpg" alt="Close-up of a white horse grazing" sizes="(min-width: 48rem) 58vw, 100vw" class="graze-focus h-photo-band w-full object-cover" />
        <x-img src="camp.jpg" alt="Riders at camp in the golden evening light" sizes="(min-width: 48rem) 42vw, 100vw" class="camp-focus hidden h-photo-band w-full object-cover md:block" />
    </div>

    <section id="trainer" class="bg-earth-2 py-section" aria-labelledby="trainer-title">
        <div class="wrap grid items-center gap-10 md:grid-cols-2 md:gap-20">
            <x-img src="tent.jpg" alt="A horse in a blanket seen through a tent opening at dusk" sizes="(min-width: 48rem) 34rem, 100vw" class="aspect-3/4 w-full object-cover" />
            <div>
                <h2 id="trainer-title" class="mb-7 text-display-xl">Meet {{ $facts->trainer() }}.</h2>
                <div class="flex flex-col gap-4.5">
                    <p>Omar was born into a polo family, but chose a different path. He believes competitive equestrian sport turns horses into equipment and breaks the bond between horse and rider.</p>
                    <p>His focus is the older relationship: trail, endurance and cross-country riding, covering new ground together. Every session is built around earning a horse’s trust.</p>
                </div>
                <p class="mt-9 border-l-3 border-saddle pl-6 font-display text-display-sm font-extrabold">Win the horse’s trust, and he’ll take you anywhere.</p>
                <a href="{{ route('trainer') }}" class="mt-9 inline-flex items-center gap-2 font-semibold underline underline-offset-4">More about Omar <x-glyph name="arrow-right" class="size-4" /></a>
            </div>
        </div>
    </section>

    <section id="prices" class="wrap py-section" aria-labelledby="prices-title">
        <x-section-heading id="prices-title" title="Sessions and prices." lede="Start with a single session. If you love it, a package takes you from complete beginner to a confident trail rider." />
        <div class="mt-16 border-t border-sand">
            @foreach ($products as $product)
                <x-price-row
                    :title="$product->name"
                    :amount-minor="$product->price_minor"
                    :caption="$product->isPackage() ? \App\Support\Money::format($product->perSessionMinor()).' per session' : 'per rider'"
                    :summary="$product->isPackage() ? $product->sessions_count.' sessions, valid '.$product->validityWeeks().' weeks.' : 'One '.$facts->sessionLengthAdjective().' session.'"
                    :featured="$product->is_featured"
                >
                    {{ $product->short_description }}
                    <a href="{{ route('sessions.show', $product) }}" class="mt-2 block font-semibold text-sand underline underline-offset-4">Details<span class="sr-only"> about the {{ $product->name }}</span></a>
                </x-price-row>
            @endforeach
        </div>
        <p class="mt-6 text-sm text-sand-dim">Session timings shift slightly with the seasons. We confirm your exact slot when you book. <a href="{{ route('prices') }}" class="underline underline-offset-4">Full prices and policies</a>.</p>
    </section>

    {{-- Approved reviews (milestone 10d). No AggregateRating here: Google ignores self-serving business ratings. --}}
    @if ($reviews->isNotEmpty())
        <section id="reviews" class="wrap pb-section" aria-labelledby="reviews-title">
            <x-section-heading id="reviews-title" title="What riders say." size="lg" />
            <x-review-list :reviews="$reviews" class="mt-10" />
        </section>
    @endif

    @if ($facts->mapUrl() !== null)
        <section id="where" class="wrap pb-section" aria-labelledby="where-title">
            <x-venue-map />
        </section>
    @endif

    <section id="book" class="wrap pb-section" aria-labelledby="book-title">
        <div class="grid gap-14 md:grid-cols-2 md:gap-20">
            <div>
                <h2 id="book-title" class="text-display-xl">How to book.</h2>
                <ol class="step-list mt-9">
                    <li><p>Choose your session and a weekend slot online.</p></li>
                    <li><p>Pay by card, or by bank transfer using your booking reference.</p></li>
                    <li><p>Get your ticket by email, with directions to the ranch.</p></li>
                    <li><p>Come in long trousers and closed shoes.</p></li>
                </ol>
            </div>
            <div class="flex flex-col justify-end gap-6">
                <p class="max-w-prose text-lede text-sand-dim">Questions first? Message us on Instagram and we’ll help you choose.</p>
                <div class="flex flex-wrap gap-3.5">
                    <x-button :href="BookController::urlFor()">Book a session</x-button>
                    <x-button :href="app(\App\Support\Settings::class)->string(\App\Enums\SettingKey::InstagramDmUrl)" variant="ghost" external>Message us on Instagram</x-button>
                </div>
            </div>
        </div>
    </section>

    <section class="top-scrim relative overflow-hidden" aria-labelledby="north-title">
        <x-img src="group.jpg" alt="A line of riders on horseback raising their fists in front of a mountain" sizes="100vw" class="w-full object-cover object-bottom md:absolute md:inset-0 md:object-top" />
        <div class="wrap relative z-10 py-14 md:min-h-north md:py-24">
            <h2 id="north-title" class="text-display-xl">Learn in {{ $facts->location() }}.<br>Ride the mountains next.</h2>
            <p class="mt-6 mb-8 max-w-prose text-lede">Our weekend sessions are the first step. When you’re ready, join a Ride Pakistan expedition through the northern valleys.</p>
            <x-button :href="route('expeditions')" variant="ghost">Explore expeditions</x-button>
        </div>
    </section>

    @if ($faqs->isNotEmpty())
        <section class="wrap max-w-narrow py-section" aria-labelledby="faq-title">
            <h2 id="faq-title" class="mb-12 text-display-xl">Questions.</h2>
            @foreach ($faqs as $faq)
                <x-faq-item :question="$faq->question" group="home-faq">{{ $faq->answer }}</x-faq-item>
            @endforeach
            <a href="{{ route('faq') }}" class="mt-9 inline-flex items-center gap-2 font-semibold underline underline-offset-4">All questions <x-glyph name="arrow-right" class="size-4" /></a>
        </section>
    @endif
</x-layouts.app>
