{{-- /team/sessions: today's and upcoming sessions for the run sheet (milestone 10b). Trainers see their own. --}}
@php
    $tz = config('app.display_timezone');
    $byDay = $slots->groupBy(fn ($slot) => $slot->starts_at->setTimezone($tz)->format('Y-m-d'));
@endphp

<x-layouts.app title="Run sheets" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-8 pb-section" aria-labelledby="sessions-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Team</p>
        <h1 id="sessions-title" class="mt-2 text-display-lg">{{ $isTrainer ? 'My sessions' : 'Sessions' }}</h1>
        <p class="mt-3 text-sand-dim">Today and the next {{ \App\Http\Controllers\Team\RunSheetController::DAYS_AHEAD }} days, Pakistan time.</p>

        @if ($slots->isEmpty())
            <x-alert class="mt-8" variant="info" title="No sessions">
                {{ $isTrainer ? 'No sessions are assigned to you in this period. An admin sets the trainer on the schedule rule or the slot.' : 'There are no sessions in this period.' }}
            </x-alert>
        @endif

        @foreach ($byDay as $date => $daySlots)
            @php $day = \Carbon\CarbonImmutable::parse($date, $tz); @endphp
            <h2 class="mt-10 text-display-xs">{{ $day->isSameDay($today) ? 'Today, ' : '' }}{{ $day->format('l j M') }}</h2>
            <ul class="mt-4 flex flex-col gap-3" role="list">
                @foreach ($daySlots as $slot)
                    <li>
                        <a href="{{ route('team.sessions.show', $slot) }}" class="flex items-center justify-between gap-4 rounded-sm border border-line-strong bg-earth-2 p-5 hover:border-sand focus-visible:outline-offset-4">
                            <span>
                                <span class="block text-lg font-semibold">{{ $slot->localStartsAt()->format('H:i') }}–{{ $slot->localEndsAt()->format('H:i') }}</span>
                                <span class="block text-sm text-sand-dim">
                                    {{ $slot->trainer?->displayName() ?? 'No trainer set' }}
                                    @if ($slot->status === \App\Enums\SlotStatus::Closed) · Closed @endif
                                </span>
                                <x-weather-badge :session="$slot" class="mt-2" />
                            </span>
                            <span class="text-right">
                                <span class="block text-lg font-semibold">{{ (int) $slot->seats_booked }}/{{ $slot->capacity }}</span>
                                <span class="block text-sm text-sand-dim">riders</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </section>
</x-layouts.app>
