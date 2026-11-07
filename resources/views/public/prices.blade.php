{{-- /prices: lesson options, pricing, riding rules and booking policies (inspiration 02). --}}
@php
    use App\Http\Controllers\Public\BookController;
    use App\Support\Money;
    $from = $facts->fromPriceMinor();
    $answer = 'Riding sessions in '.$facts->location()
        .($from !== null ? ' start at '.Money::format($from).' per rider for one '.$facts->sessionLengthAdjective().' session' : ' are priced per rider')
        .'. Packages bring the price per session down and are valid for a set number of weeks from confirmation. Sessions run '
        .$facts->scheduleText().', and no experience is needed.';
@endphp

<x-layouts.app :seo="$seo">
    <x-page-header
        title="Prices."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Prices']]"
        :answer="$answer"
    >

        <x-button :href="BookController::urlFor()">Book a session</x-button>
    </x-page-header>

    <section class="wrap pb-section" aria-labelledby="options-title">
        <x-section-heading id="options-title" title="Lesson options." size="lg" />
        <div class="mt-10 grid gap-8 md:grid-cols-2">
            @foreach ([...$sessions, ...$packages] as $product)
                <article class="flex flex-col gap-3 border-t border-line pt-6">
                    <h3 class="text-display-sm"><a href="{{ route('sessions.show', $product) }}" class="underline-offset-4 hover:underline">{{ $product->name }}</a></h3>
                    <p class="text-sand-dim">{{ $product->who_for ?: $product->short_description }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="wrap pb-section" aria-labelledby="pricing-title">
        <x-section-heading id="pricing-title" title="Pricing." size="lg" lede="Prices are per rider, in Pakistani rupees." />

        @if ($sessions->isNotEmpty())
            <h3 class="mt-12 font-display text-display-xs font-extrabold">Single visits</h3>
            <div class="mt-4 border-t border-sand">
                @foreach ($sessions as $product)
                    <x-price-row :title="$product->name" :amount-minor="$product->price_minor" caption="per rider" :summary="'One '.$facts->sessionLengthAdjective().' session.'" :featured="$product->is_featured" :level="4">
                        {{ $product->short_description }}
                    </x-price-row>
                @endforeach
            </div>
        @endif

        @if ($packages->isNotEmpty())
            <h3 class="mt-16 font-display text-display-xs font-extrabold">Packages</h3>
            <div class="mt-4 border-t border-sand">
                @foreach ($packages as $product)
                    <x-price-row
                        :title="$product->name"
                        :amount-minor="$product->price_minor"
                        :caption="Money::format($product->perSessionMinor()).' per session'"
                        :summary="$product->sessions_count.' sessions, valid '.$product->validityWeeks().' weeks from confirmation.'"
                        :featured="$product->is_featured"
                        :label="$product->is_featured ? 'Most popular' : null"
                        :level="4"
                    >
                        {{ $product->short_description }}
                    </x-price-row>
                @endforeach
            </div>
            <p class="mt-6 max-w-prose text-sm text-sand-dim">Package sessions are credits: book them as you go from your account. Credits that pass their validity can’t be used, so plan around two sessions a week.</p>
        @endif
    </section>

    <section id="policies" class="bg-earth-2 py-section" aria-labelledby="rules-title">
        <div class="wrap grid gap-14 md:grid-cols-2 md:gap-20">
            @if ($ridingRules !== [])
                <div>
                    <h2 id="rules-title" class="mb-8 text-display-lg">Riding rules.</h2>
                    <ul class="tick-list">
                        @foreach ($ridingRules as $rule)
                            <li>{{ $rule }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($bookingPolicies !== [])
                <div>
                    <h2 class="mb-8 text-display-lg">Booking policies.</h2>
                    <ul class="tick-list">
                        @foreach ($bookingPolicies as $policy)
                            <li>{{ $policy }}</li>
                        @endforeach
                    </ul>
                    @if ($refundPolicy !== '')
                        <p class="mt-6 text-sand-dim">{{ $refundPolicy }}</p>
                    @endif
                </div>
            @endif
        </div>
    </section>

    <section class="wrap py-section" aria-labelledby="ask-title">
        <div class="flex flex-col items-start gap-6 md:flex-row md:items-end md:justify-between">
            <div>
                <h2 id="ask-title" class="text-display-lg">Still have a question?</h2>
                <p class="mt-4 max-w-prose text-lede text-sand-dim">Read the <a href="{{ route('faq') }}" class="underline underline-offset-4">FAQ</a> or send us a message.</p>
            </div>
            <x-button :href="route('contact')" variant="ghost">Ask a question</x-button>
        </div>
    </section>
</x-layouts.app>
