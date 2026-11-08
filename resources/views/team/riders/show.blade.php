{{-- /team/riders/{rider}: the rider's sessions with horse, attendance and session notes (milestone 10b). No money. --}}
<x-layouts.app title="Rider history: {{ $rider->name }}" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-8 pb-section" aria-labelledby="rider-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">
            <a href="{{ route('team.sessions.index') }}" class="underline underline-offset-4 hover:text-sand">Sessions</a> · Rider history
        </p>
        <h1 id="rider-title" class="mt-2 text-display-lg">{{ $rider->name }}</h1>

        <dl class="mt-6 grid gap-4 rounded-sm border border-line-strong bg-earth-2 p-5 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-sand-dim">Age</dt>
                <dd class="font-semibold">{{ $rider->age_bracket?->label() ?? 'Not given' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-sand-dim">Experience</dt>
                <dd class="font-semibold">{{ $rider->experience_level?->label() ?? 'Not given' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-sm text-sand-dim">Notes for the trainer</dt>
                <dd>{{ $rider->notes ?: 'None' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-sm text-sand-dim">Emergency contact</dt>
                <dd>
                    {{ $rider->emergency_contact_name ?: 'Not given' }}
                    @if ($rider->emergency_contact_phone)
                        · <a href="tel:{{ $rider->emergency_contact_phone }}" class="font-semibold underline underline-offset-4 hover:text-sand-dim">{{ $rider->emergency_contact_phone }}</a>
                    @endif
                </dd>
            </div>
        </dl>

        {{-- Skills reached (milestone 10d), ticked from the run sheet. --}}
        @php($reached = $rider->riderSkills()->with('skill')->get()->sortBy(fn ($r) => [$r->skill->sort, $r->skill_id]))
        <h2 class="mt-10 text-display-xs">Skills</h2>
        @if ($reached->isEmpty())
            <p class="mt-4 text-sand-dim">No skills ticked yet.</p>
        @else
            <ul class="mt-4 flex flex-wrap gap-2" role="list">
                @foreach ($reached as $item)
                    <li><x-badge variant="success">{{ $item->skill->name }} · {{ $item->achieved_at->setTimezone(config('app.display_timezone'))->format('j M Y') }}</x-badge></li>
                @endforeach
            </ul>
        @endif

        <h2 class="mt-10 text-display-xs">Sessions</h2>

        @if ($bookings->isEmpty())
            <p class="mt-4 text-sand-dim">No sessions yet.</p>
        @else
            <ol class="mt-4 flex flex-col gap-3" role="list">
                @foreach ($bookings as $booking)
                    <li class="rounded-sm border border-line-strong bg-earth-2 p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <p class="font-semibold">{{ $booking->slot->label() }}</p>
                            <x-badge :variant="$booking->status === \App\Enums\BookingStatus::Attended ? 'success' : 'neutral'">{{ $booking->status->label() }}</x-badge>
                        </div>
                        <p class="mt-1 text-sm text-sand-dim">
                            Horse: {{ $booking->horse?->name ?? 'not assigned' }}
                            · Trainer: {{ $booking->slot->trainer?->displayName() ?? 'not set' }}
                        </p>
                        @if ($booking->session_note)
                            <p class="mt-3 whitespace-pre-line">{{ $booking->session_note }}</p>
                        @endif
                        @if ($booking->progress_note)
                            <p class="mt-3 whitespace-pre-line text-sand-dim"><span class="font-semibold text-sand">Note for the family:</span> {{ $booking->progress_note }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</x-layouts.app>
