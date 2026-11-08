<x-layouts.account title="Progress" intro="What each rider can do so far, ticked by the trainer after their sessions.">
    @if ($riders->isEmpty())
        <p class="text-sand-dim">Add your riders and book a session; their progress shows here after they ride. <a href="{{ route('account.riders') }}" class="underline underline-offset-4 hover:text-sand">Add a rider</a>.</p>
    @else
        @foreach ($riders as $item)
            @php($rider = $item['rider'])
            <section aria-labelledby="rider-{{ $rider->id }}-title" class="flex flex-col gap-4 rounded-sm border border-line bg-earth-2 p-5 md:p-7">
                <header class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 id="rider-{{ $rider->id }}-title" class="text-display-xs">{{ $rider->name }}</h2>
                    <p class="text-sm text-sand-dim">{{ $item['count'] }} of {{ $total }} skills</p>
                </header>

                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($item['skills'] as $skill)
                        @php($has = $item['reached']->get($skill->id))
                        <li @class(['flex items-start gap-3 rounded-sm border p-3', 'border-success' => $has, 'border-line' => ! $has])>
                            <x-glyph :name="$has ? 'success' : 'circle'" class="mt-0.5 size-5 {{ $has ? 'text-success' : 'text-sand-dim' }}" />
                            <span class="flex flex-col">
                                <span @class(['font-semibold', 'text-sand' => $has, 'text-sand-dim' => ! $has])>{{ $skill->name }}<span class="sr-only">: {{ $has ? 'done' : 'not yet' }}</span></span>
                                @if ($has)
                                    <span class="text-sm text-sand-dim">Since {{ $has->achieved_at->setTimezone($timezone)->format('j M Y') }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if ($item['notes']->isNotEmpty())
                    <div class="flex flex-col gap-3">
                        <h3 class="text-sm font-semibold uppercase tracking-label text-sand-dim">From the trainer</h3>
                        <ul class="flex flex-col gap-3">
                            @foreach ($item['notes'] as $booking)
                                <li class="border-l-3 border-line-strong pl-4">
                                    <p class="text-sm text-sand-dim"><time datetime="{{ $booking->slot->starts_at->toIso8601String() }}">{{ $booking->slot->localStartsAt()->format('D j M Y') }}</time></p>
                                    <p class="whitespace-pre-line">{{ $booking->progress_note }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endforeach

        @if ($next)
            <aside aria-labelledby="next-steps-title" class="flex flex-col gap-3 rounded-sm border border-line-strong p-5 md:p-7">
                <h2 id="next-steps-title" class="text-display-xs">Next steps: {{ $next['title'] }}</h2>
                <p class="max-w-prose text-sand-dim">{{ $next['body'] }}</p>
                <div>
                    <x-button :href="$next['url']">{{ $next['label'] }}</x-button>
                </div>
            </aside>
        @endif
    @endif
</x-layouts.account>
