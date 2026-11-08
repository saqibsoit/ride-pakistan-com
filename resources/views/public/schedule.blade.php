{{-- /schedule: week calendar of live slots (rule and manual) with seats left (inspiration 02). --}}
@php
    $tz = (string) config('app.display_timezone');
    $sunday = $monday->addDays(6);
    $privateProduct = $facts->products()->first(fn ($p) => $p->type === \App\Enums\ProductType::Private);
    $weekLink = fn (\Carbon\CarbonImmutable $week): string => route('schedule', array_filter(['week' => $week->format('Y-m-d'), 'type' => $type]));
    $range = $monday->month === $sunday->month
        ? $monday->format('j').'–'.$sunday->format('j F Y')
        : $monday->format('j M').' – '.$sunday->format('j M Y');
@endphp

<x-layouts.app :seo="$seo">
    <x-page-header
        title="Weekly schedule."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Schedule']]"
        :answer="'Sessions run '.$facts->scheduleText().' in '.$facts->location().', with trainer '.$facts->trainer().'. Pick a slot to book; times are Pakistan time and shift slightly with the seasons.'"
    />

    <section class="wrap pb-section" aria-labelledby="week-title">
        <x-filter-chips label="Show slots for" class="mb-6">
            <x-filter-chip :href="route('schedule', array_filter(['week' => $isCurrent ? null : $monday->format('Y-m-d')]))" :active="$type === null">All slots</x-filter-chip>
            <x-filter-chip :href="route('schedule', array_filter(['week' => $isCurrent ? null : $monday->format('Y-m-d'), 'type' => 'group']))" :active="$type === 'group'">Group sessions</x-filter-chip>
            <x-filter-chip :href="route('schedule', array_filter(['week' => $isCurrent ? null : $monday->format('Y-m-d'), 'type' => 'private']))" :active="$type === 'private'">Free for a private session</x-filter-chip>
        </x-filter-chips>

        <div class="mb-8 flex flex-wrap items-center justify-between gap-4 border-y border-line py-4">
            @if ($previous)
                <a href="{{ $weekLink($previous) }}" class="inline-flex min-h-11 items-center gap-2 font-semibold underline-offset-4 hover:underline" rel="prev">
                    <x-glyph name="arrow-left" class="size-4" />Previous<span class="sr-only"> week</span>
                </a>
            @else
                <span class="inline-flex min-h-11 items-center gap-2 text-sand-dim" aria-hidden="true"><x-glyph name="arrow-left" class="size-4" />Previous</span>
            @endif

            <h2 id="week-title" class="font-display text-display-xs font-extrabold">
                {{ $isCurrent ? 'This week' : 'Week of' }} <span class="text-sand-dim">· {{ $range }}</span>
            </h2>

            @if ($next)
                <a href="{{ $weekLink($next) }}" class="inline-flex min-h-11 items-center gap-2 font-semibold underline-offset-4 hover:underline" rel="next">
                    Next<span class="sr-only"> week</span><x-glyph name="arrow-right" class="size-4" />
                </a>
            @else
                <span class="inline-flex min-h-11 items-center gap-2 text-sand-dim" aria-hidden="true">Next<x-glyph name="arrow-right" class="size-4" /></span>
            @endif
        </div>

        <ol class="grid gap-4 lg:grid-cols-7">
            @foreach ($days as $date => $slots)
                @php($day = \Carbon\CarbonImmutable::parse($date, $tz))
                @php($isWeekend = $day->isWeekend())
                <li @class([
                    'flex flex-col gap-3 rounded-sm p-3',
                    'bg-earth-2' => $isWeekend,
                    'hidden lg:flex' => ! $isWeekend && $slots->isEmpty(),
                ])>
                    <h3 class="flex items-baseline gap-2 border-b border-line pb-2">
                        <span class="font-display text-display-xs font-extrabold">{{ $day->format('D') }}</span>
                        <span class="text-sm text-sand-dim">{{ $day->format('j M') }}</span>
                    </h3>
                    @forelse ($slots as $slot)
                        @php($open = $slot->status === \App\Enums\SlotStatus::Open)
                        <x-slot-card
                            :starts-at="$slot->starts_at"
                            :ends-at="$slot->ends_at"
                            :product="$type === 'private' ? 'Private session' : ($slot->isPrivatelyBooked() ? 'Private session' : 'Riding session')"
                            :trainer="$facts->trainer()"
                            :level="4"
                            :seats="$open ? $slot->seatsFree() : null"
                            note="Closed for booking"
                            :href="$open ? route('book', array_filter(['slot' => $slot->id, 'product' => $type === 'private' ? $privateProduct?->slug : null])) : null"
                        />
                    @empty
                        <p class="text-sm text-sand-dim">{{ $isWeekend ? 'No more sessions' : 'No sessions' }}</p>
                    @endforelse
                </li>
            @endforeach
        </ol>

        <p class="mt-8 text-sm text-sand-dim">
            All sessions suit beginners. Private sessions take a whole slot; packages let you book your sessions as you go.
            <a href="{{ route('prices') }}" class="underline underline-offset-4">See prices</a>.
        </p>
    </section>
</x-layouts.app>
