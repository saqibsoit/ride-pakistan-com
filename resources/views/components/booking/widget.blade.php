{{--
    Inline booking widget on product pages (inspiration 01): next slots with
    seats left, rider count, then the /book flow pre-filled. A plain GET
    form, so it works without JavaScript and keeps Livewire off marketing
    pages; the rider stepper is progressive enhancement (Alpine "stepper").

    <x-booking.widget :product="$product" :slots="$slots" />
--}}
@props(['product', 'slots'])

@php
    $isPackage = $product->isPackage();
    $isPrivate = $product->type === \App\Enums\ProductType::Private;
    $maxRiders = $isPackage ? max(1, $product->sessions_count) : (int) (app(\App\Support\Settings::class)->int(\App\Enums\SettingKey::RidersPerSlot) ?? 6);
@endphp

<section id="book" aria-labelledby="widget-title" {{ $attributes->class('rounded-sm border border-line-strong bg-earth-2 p-7 md:p-10') }}>
    <h2 id="widget-title" class="text-display-md">Book this session.</h2>

    <form method="GET" action="{{ route('book') }}" class="mt-6 flex flex-col gap-6">
        <input type="hidden" name="product" value="{{ $product->slug }}">

        <fieldset class="flex flex-col gap-3">
            <legend class="mb-3 text-sm font-semibold">
                {{ $isPackage ? 'First session (optional)' : 'Choose a slot' }}
                <span class="font-normal text-sand-dim">· Pakistan time</span>
            </legend>

            @if ($isPackage)
                <label class="flex cursor-pointer items-center gap-3 rounded-sm border border-line-strong p-4 has-checked:border-sand">
                    <input type="radio" name="slot" value="" checked class="size-5 accent-sand">
                    <span class="font-semibold">I’ll choose my dates later</span>
                </label>
            @endif

            @forelse ($slots as $option)
                @php($seats = $option->seatsFree())
                <label class="flex cursor-pointer flex-wrap items-center justify-between gap-3 rounded-sm border border-line-strong p-4 has-checked:border-sand">
                    <span class="flex items-center gap-3">
                        <input type="radio" name="slot" value="{{ $option->id }}" @checked(! $isPackage && $loop->first) class="size-5 accent-sand">
                        <time datetime="{{ $option->starts_at->toIso8601String() }}" class="font-semibold">{{ $option->localStartsAt()->format('D j M') }}, {{ \App\Support\BusinessFacts::timeRange($option->localStartsAt()->format('H:i'), $option->localEndsAt()->format('H:i')) }}</time>
                    </span>
                    @if ($isPrivate)
                        <span class="text-sm text-sand-dim">Whole slot free</span>
                    @else
                        <x-seats-left :seats="$seats" />
                    @endif
                </label>
            @empty
                <p class="text-sand-dim">No slots open in the next few weeks. <a href="{{ route('schedule') }}" class="underline underline-offset-4">See the schedule</a> or message us.</p>
            @endforelse

            @unless ($isPrivate)
                {{-- Full slots aren't listed here; the schedule offers their waitlist (milestone 10d). --}}
                <p class="text-sm text-sand-dim">The date you want is full? <a href="{{ route('schedule') }}" class="underline underline-offset-4">Join its waitlist from the schedule</a>.</p>
            @endunless
        </fieldset>

        @if ($slots->isNotEmpty() || $isPackage)
            <div class="flex flex-col gap-2" x-data="stepper" data-min="1" data-max="{{ $maxRiders }}">
                <label for="widget-riders" class="text-sm font-semibold">{{ $isPackage ? 'Riders on the first session' : 'Riders' }}</label>
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="decrement" x-bind:disabled="atMin" class="inline-flex size-11 items-center justify-center rounded-sm border border-line-strong text-lede hover:border-sand disabled:opacity-60" aria-label="One rider fewer" x-cloak>−</button>
                    <input id="widget-riders" type="number" name="riders" value="1" min="1" max="{{ $maxRiders }}" inputmode="numeric" x-ref="input" class="w-20 rounded-sm border border-line-strong bg-earth px-3 py-2.5 text-center text-base text-sand">
                    <button type="button" x-on:click="increment" x-bind:disabled="atMax" class="inline-flex size-11 items-center justify-center rounded-sm border border-line-strong text-lede hover:border-sand disabled:opacity-60" aria-label="One rider more" x-cloak>+</button>
                </div>
            </div>

            <div>
                <x-button type="submit">Continue</x-button>
            </div>
        @endif
    </form>
</section>
