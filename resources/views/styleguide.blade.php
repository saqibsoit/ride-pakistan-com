@php
    use Illuminate\Support\Carbon;

    // Demo slots: next weekend, 08:00 and 15:00 Asia/Karachi, stored as UTC.
    $tz = (string) config('app.display_timezone');
    $saturday = Carbon::now($tz)->next(Carbon::SATURDAY)->startOfDay();
    $sunday = $saturday->copy()->addDay();
    $slot = fn (Carbon $day, int $hour): array => [
        'starts' => $day->copy()->setTime($hour, 0)->utc(),
        'ends' => $day->copy()->setTime($hour + 2, 0)->utc(),
    ];

    $colours = [
        ['name' => 'earth', 'class' => 'bg-earth', 'note' => 'Page background'],
        ['name' => 'earth-2', 'class' => 'bg-earth-2', 'note' => 'Raised surface'],
        ['name' => 'saddle', 'class' => 'bg-saddle', 'note' => '3.1:1 on earth: large numerals and decoration only'],
        ['name' => 'sand', 'class' => 'bg-sand', 'note' => 'Text 14.8:1 on earth'],
        ['name' => 'sand-dim', 'class' => 'bg-sand-dim', 'note' => 'Secondary text 8.1:1'],
        ['name' => 'line', 'class' => 'bg-line', 'note' => 'Dividers (decorative)'],
        ['name' => 'line-strong', 'class' => 'bg-line-strong', 'note' => 'Field and chip borders 3.9:1'],
        ['name' => 'error', 'class' => 'bg-error', 'note' => 'Error text 7.5:1'],
        ['name' => 'success', 'class' => 'bg-success', 'note' => 'Success text 9.5:1'],
        ['name' => 'warning', 'class' => 'bg-warning', 'note' => 'Warning text 9.6:1'],
    ];

    $typeScale = [
        ['token' => 'text-display-xl', 'class' => 'font-display font-black text-display-xl', 'sample' => 'Sessions and prices.'],
        ['token' => 'text-display-lg', 'class' => 'font-display font-black text-display-lg', 'sample' => 'PKR 10,000'],
        ['token' => 'text-display-md', 'class' => 'font-display font-black text-display-md', 'sample' => 'Beginner to trail rider'],
        ['token' => 'text-display-sm', 'class' => 'font-display font-black text-display-sm', 'sample' => 'Bring him in'],
        ['token' => 'text-display-xs', 'class' => 'font-display font-extrabold text-display-xs', 'sample' => 'What should I wear?'],
        ['token' => 'text-lede', 'class' => 'text-lede', 'sample' => 'Weekend horsemanship and trail riding in Islamabad.'],
        ['token' => 'text-base', 'class' => 'text-base', 'sample' => 'Brush him down and check his hooves. It’s the quiet part of the session.'],
        ['token' => 'text-sm', 'class' => 'text-sm', 'sample' => 'Session timings shift slightly with the seasons.'],
        ['token' => 'text-xs', 'class' => 'text-xs font-semibold uppercase tracking-label', 'sample' => 'Lessons'],
    ];
@endphp

<x-layouts.app title="Styleguide" robots="noindex, nofollow" overlay-header>
    <x-hero
        image="hero.jpg"
        image-alt="A rider resting his forehead against a white horse"
        :lines="['Never touched', 'a horse?', 'Perfect.']"
        lede="Weekend horsemanship and trail riding in Islamabad. Two hours with a horse, from first hello to your first ride, with trainer Omar Saleem."
    >
        <x-button href="#prices">See sessions and prices</x-button>
        <x-button :href="config('site.instagram_dm_url')" variant="ghost" external>Message us on Instagram</x-button>
    </x-hero>

    <x-fact-strip label="Session facts">
        <x-fact value="Sat & Sun" label="Every weekend" />
        <x-fact value="8–10 am" label="or 3–5 pm" />
        <x-fact value="2 hours" label="Per session" />
        <x-fact value="Islamabad" label="No experience needed" />
    </x-fact-strip>

    <div class="wrap flex flex-col gap-24 py-section">
        <x-breadcrumb :items="[['label' => 'Home', 'url' => url('/')], ['label' => 'Styleguide']]" />

        {{-- Colours --}}
        <section aria-labelledby="sg-colours" class="flex flex-col gap-8">
            <x-section-heading id="sg-colours" eyebrow="Tokens" title="Colours" size="md" lede="Every colour on the site comes from resources/css/tokens.css. Contrast ratios are measured against earth." />
            <ul class="grid grid-cols-2 gap-4 md:grid-cols-5">
                @foreach ($colours as $colour)
                    <li class="flex flex-col gap-2">
                        <span class="block h-20 rounded-sm border border-line {{ $colour['class'] }}"></span>
                        <span class="font-semibold">{{ $colour['name'] }}</span>
                        <span class="text-xs text-sand-dim">{{ $colour['note'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Type --}}
        <section aria-labelledby="sg-type" class="flex flex-col gap-8">
            <x-section-heading id="sg-type" eyebrow="Tokens" title="Type scale" size="md" lede="Big Shoulders Display for headings, Instrument Sans for text. Sizes are fluid (clamp); the hero size also follows viewport height." />
            <dl class="flex flex-col">
                @foreach ($typeScale as $row)
                    <div class="grid gap-2 border-t border-line py-5 md:grid-cols-4">
                        <dt class="text-sm text-sand-dim">{{ $row['token'] }}</dt>
                        <dd class="md:col-span-3 {{ $row['class'] }}">{{ $row['sample'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- Buttons and badges --}}
        <section aria-labelledby="sg-buttons" class="flex flex-col gap-8">
            <x-section-heading id="sg-buttons" eyebrow="Components" title="Buttons and badges" size="md" />
            <div class="flex flex-wrap items-center gap-4">
                <x-button href="#sg-buttons">Primary link</x-button>
                <x-button variant="ghost">Ghost button</x-button>
                <x-button size="sm">Small primary</x-button>
                <x-button size="sm" variant="ghost">Small ghost</x-button>
                <x-button disabled>Disabled</x-button>
            </div>
            <div class="surface-sand flex flex-wrap items-center gap-4 rounded-sm p-8">
                <p class="font-semibold">On a sand surface:</p>
                <x-button variant="dark">Dark button</x-button>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-badge>Neutral</x-badge>
                <x-badge variant="featured">Featured</x-badge>
                <x-badge variant="selling-fast">Selling fast</x-badge>
                <x-badge variant="price">PKR 10,000 per rider</x-badge>
                <x-badge variant="success">Paid</x-badge>
                <x-badge variant="warning">Awaiting transfer</x-badge>
                <x-badge variant="error">Rejected</x-badge>
            </div>
        </section>

        {{-- Catalog --}}
        <section aria-labelledby="sg-catalog" class="flex flex-col gap-8">
            <x-section-heading id="sg-catalog" eyebrow="Components" title="Experience cards and filters" size="md" />
            <x-filter-chips label="Filter experiences">
                <x-filter-chip href="#sg-catalog" active>All</x-filter-chip>
                <x-filter-chip href="#sg-catalog" :count="2">Lessons</x-filter-chip>
                <x-filter-chip href="#sg-catalog">Private</x-filter-chip>
                <x-filter-chip href="#sg-catalog">Packages</x-filter-chip>
                <x-filter-chip>Expeditions</x-filter-chip>
            </x-filter-chips>
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <x-experience-card
                    title="Single session"
                    href="#sg-catalog"
                    book-href="#sg-booking"
                    image="ride.jpg"
                    image-alt="A rider on a trail with mountains behind"
                    category="Lessons"
                    :price-minor="1000000"
                    description="Two hours with a horse: bring him in, groom, tack up, ride the trail and cool down. Made for complete beginners."
                    duration="2 hours"
                    group-size="Up to 6 riders"
                    location="Islamabad"
                    featured
                />
                <x-experience-card
                    title="Private session"
                    href="#sg-catalog"
                    book-href="#sg-booking"
                    image="meet.jpg"
                    image-alt="A man gently touching a grazing white horse"
                    category="Private"
                    :price-minor="1500000"
                    description="Just you, or you and your people. Your own trainer and pace on a weekend slot."
                    duration="2 hours"
                    location="Islamabad"
                    video-href="https://www.instagram.com/ridepakistan/"
                />
                <x-experience-card
                    title="16-class package"
                    href="#sg-catalog"
                    book-href="#sg-booking"
                    image="gallop.jpg"
                    image-alt="Riders galloping across open ground"
                    category="Packages"
                    :price-minor="10000000"
                    price-unit="16 sessions"
                    description="Two sessions a week for ten weeks, from your first time touching a horse to riding the trail with confidence."
                    duration="10 weeks"
                    group-size="Up to 6 riders"
                />
            </div>
        </section>

        {{-- Schedule --}}
        <section aria-labelledby="sg-schedule" class="flex flex-col gap-8">
            <x-section-heading id="sg-schedule" eyebrow="Components" title="Week calendar slots" size="md" lede="Times are stored in UTC and shown in Asia/Karachi." />
            <div class="grid gap-6 md:grid-cols-2">
                @foreach ([$saturday, $sunday] as $day)
                    <div class="flex flex-col gap-4">
                        <h3 class="font-display text-display-xs font-extrabold">{{ $day->format('l j F') }}</h3>
                        @php($morning = $slot($day, 8))
                        @php($afternoon = $slot($day, 15))
                        <x-slot-card :starts-at="$morning['starts']" :ends-at="$morning['ends']" product="Single session" trainer="Omar Saleem" :seats="$loop->first ? 4 : 2" href="#sg-booking" level="4" />
                        <x-slot-card :starts-at="$afternoon['starts']" :ends-at="$afternoon['ends']" product="Single session" trainer="Omar Saleem" :seats="$loop->first ? 1 : 0" href="#sg-booking" level="4" />
                    </div>
                @endforeach
            </div>
            <div class="flex flex-col gap-2">
                <p class="text-sm text-sand-dim">Seats-left states:</p>
                <x-seats-left :seats="6" />
                <x-seats-left :seats="2" />
                <x-seats-left :seats="1" />
                <x-seats-left :seats="0" />
            </div>
        </section>

        {{-- Booking box --}}
        <section id="sg-booking" aria-labelledby="sg-booking-title" class="flex flex-col gap-8">
            <x-section-heading id="sg-booking-title" eyebrow="Components" title="Price and booking box" size="md" lede="Sticky box on desktop; on smaller screens the fixed bottom bar takes over." />
            <div class="grid gap-10 lg:grid-cols-3">
                <div class="flex flex-col gap-6 lg:col-span-2">
                    <x-img src="graze.jpg" alt="Close-up of a white horse grazing" sizes="(min-width: 64rem) 48rem, 100vw" class="aspect-3/2 w-full rounded-sm object-cover" />
                    <p class="max-w-prose text-sand-dim">A two-hour beginner session every Saturday and Sunday in Islamabad. You bring the horse in, groom and tack up, ride mostly on the trail, then untack and cool him down. No experience needed.</p>
                </div>
                <x-booking.price-box :from-minor="1000000" book-href="#sg-booking" note="Exact location shared after booking.">
                    <x-seats-left :seats="2" />
                    <p class="text-sm text-sand-dim">Sat &amp; Sun · 8–10 am or 3–5 pm</p>
                </x-booking.price-box>
            </div>
        </section>

        {{-- Steps --}}
        <section aria-labelledby="sg-steps" class="flex flex-col gap-8">
            <x-section-heading id="sg-steps" eyebrow="Components" size="md">
                <x-slot:title>Two hours,<br>start to finish.</x-slot:title>
            </x-section-heading>
            <x-step-list>
                <x-step title="Bring him in">Walk out to the paddock and lead your horse in.</x-step>
                <x-step title="Groom">Brush him down and check his hooves.</x-step>
                <x-step title="Tack up">Fit the saddle and bridle yourself, with your trainer checking every buckle.</x-step>
            </x-step-list>
        </section>

        {{-- Prices --}}
        <section id="prices" aria-labelledby="sg-prices" class="flex flex-col gap-8">
            <x-section-heading id="sg-prices" eyebrow="Components" title="Price rows" size="md" />
            <div class="border-t border-sand">
                <x-price-row title="Single session" :amount-minor="1000000" caption="per rider" summary="One 2-hour session.">The full experience: groundwork, grooming, tacking up and a guided ride.</x-price-row>
                <x-price-row title="8-class package" :amount-minor="6000000" caption="PKR 7,500 per session · valid 6 weeks" summary="8 sessions, 2 per week.">Build your basics and your confidence in the saddle.</x-price-row>
                <x-price-row title="16-class package" :amount-minor="10000000" caption="PKR 6,250 per session · valid 10 weeks" summary="16 sessions, 2 per week." label="Best value" featured>From your first time touching a horse to riding the trail with confidence.</x-price-row>
                <x-price-row title="Private session" :amount-minor="1500000" caption="per rider" summary="Just you, or you and your people.">Your own trainer and pace, on a weekend slot that suits you.</x-price-row>
            </div>
        </section>

        {{-- FAQ --}}
        <section aria-labelledby="sg-faq" class="flex max-w-narrow flex-col gap-8">
            <x-section-heading id="sg-faq" eyebrow="Components" title="FAQ items" size="md" />
            <div>
                <x-faq-item question="I’ve never been near a horse. Is that okay?" group="sg-faq">Yes. The sessions are made for complete beginners.</x-faq-item>
                <x-faq-item question="What should I wear?" group="sg-faq">Full-length trousers and closed shoes or boots with a small heel.</x-faq-item>
                <x-faq-item question="Where exactly are you?" group="sg-faq">We’re in Islamabad. We share the exact location pin when we confirm your booking.</x-faq-item>
            </div>
        </section>

        {{-- Alerts --}}
        <section aria-labelledby="sg-alerts" class="flex flex-col gap-8">
            <x-section-heading id="sg-alerts" eyebrow="Components" title="Alerts" size="md" />
            <div class="grid gap-4 md:grid-cols-2">
                <x-alert>Session timings shift slightly with the seasons.</x-alert>
                <x-alert variant="success" title="Booking confirmed">Your ticket is on its way to your inbox.</x-alert>
                <x-alert variant="warning" title="Transfer pending">We hold your seats for 24 hours while we check your transfer.</x-alert>
                <x-alert variant="error" title="Payment failed">Your card was declined. You can try again or pay by bank transfer.</x-alert>
            </div>
        </section>

        {{-- Forms --}}
        <section aria-labelledby="sg-forms" class="flex flex-col gap-8">
            <x-section-heading id="sg-forms" eyebrow="Components" title="Form fields" size="md" />
            <form class="grid max-w-narrow gap-6 md:grid-cols-2" action="#" method="get" novalidate>
                <x-form.field name="name" label="Your name" required autocomplete="name" />
                <x-form.field name="email" label="Email" type="email" hint="We send your ticket here." required autocomplete="email" />
                <x-form.field name="phone" label="Phone" type="tel" error="Enter a phone number with country code, e.g. +92 300 1234567." autocomplete="tel" />
                <x-form.field name="interest" label="Interested in" type="select">
                    <option value="single">Single session</option>
                    <option value="private">Private session</option>
                    <option value="package">Package</option>
                </x-form.field>
                <div class="md:col-span-2">
                    <x-form.field name="message" label="Message" type="textarea" rows="4" />
                </div>
                <div class="md:col-span-2">
                    <x-button type="submit">Send message</x-button>
                </div>
            </form>
        </section>
    </div>

    <x-booking.bottom-bar :from-minor="1000000" book-href="#sg-booking" />
</x-layouts.app>
