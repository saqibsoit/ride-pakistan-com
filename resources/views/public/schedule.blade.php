{{-- /schedule: week calendar shell (inspiration 02). Live seats arrive in milestone 5. --}}
@php
    $tz = (string) config('app.display_timezone');
    $sunday = $monday->addDays(6);
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
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4 border-y border-line py-4">
            @if ($previous)
                <a href="{{ route('schedule', ['week' => $previous->format('Y-m-d')]) }}" class="inline-flex min-h-11 items-center gap-2 font-semibold underline-offset-4 hover:underline" rel="prev">
                    <x-glyph name="arrow-left" class="size-4" />Previous<span class="sr-only"> week</span>
                </a>
            @else
                <span class="inline-flex min-h-11 items-center gap-2 text-sand-dim" aria-hidden="true"><x-glyph name="arrow-left" class="size-4" />Previous</span>
            @endif

            <h2 id="week-title" class="font-display text-display-xs font-extrabold">
                {{ $isCurrent ? 'This week' : 'Week of' }} <span class="text-sand-dim">· {{ $range }}</span>
            </h2>

            @if ($next)
                <a href="{{ route('schedule', ['week' => $next->format('Y-m-d')]) }}" class="inline-flex min-h-11 items-center gap-2 font-semibold underline-offset-4 hover:underline" rel="next">
                    Next<span class="sr-only"> week</span><x-glyph name="arrow-right" class="size-4" />
                </a>
            @else
                <span class="inline-flex min-h-11 items-center gap-2 text-sand-dim" aria-hidden="true">Next<x-glyph name="arrow-right" class="size-4" /></span>
            @endif
        </div>

        <ol class="grid gap-4 lg:grid-cols-7">
            @foreach ($days as $day)
                @php($isWeekend = $day['date']->isWeekend())
                <li @class([
                    'flex flex-col gap-3 rounded-sm p-3',
                    'bg-earth-2' => $isWeekend,
                    'hidden lg:flex' => ! $isWeekend && $day['sessions'] === [],
                ])>
                    <h3 class="flex items-baseline gap-2 border-b border-line pb-2">
                        <span class="font-display text-display-xs font-extrabold">{{ $day['date']->format('D') }}</span>
                        <span class="text-sm text-sand-dim">{{ $day['date']->format('j M') }}</span>
                    </h3>
                    @forelse ($day['sessions'] as $session)
                        <x-slot-card
                            :starts-at="$session['starts_at']"
                            :ends-at="$session['ends_at']"
                            product="Riding session"
                            :trainer="$facts->trainer()"
                            :level="4"
                            :href="route('book', ['date' => $session['starts_at']->format('Y-m-d'), 'time' => $session['starts_at']->format('H:i')])"
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
