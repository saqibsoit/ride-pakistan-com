{{-- /schedule-change/{notice}: keep, move or cancel after a session time change (prompt 15A). Signed link, no sign-in. --}}
<x-layouts.app title="Your session has a new time" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="change-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Schedule change</p>
        <h1 id="change-title" class="mt-2 text-display-lg">Your session has a new time</h1>

        <dl class="mt-8 grid gap-4 sm:grid-cols-2">
            <div class="rounded-sm border border-line-strong p-4">
                <dt class="text-sm text-sand-dim">Was</dt>
                <dd class="mt-1 font-semibold"><s>{{ $notice->oldLabel() }}</s></dd>
            </div>
            <div class="rounded-sm border border-sand p-4">
                <dt class="text-sm text-sand-dim">Now (Pakistan time)</dt>
                <dd class="mt-1 font-semibold"><time datetime="{{ $notice->new_starts_at->toIso8601String() }}">{{ $notice->newLabel() }}</time></dd>
            </div>
        </dl>

        @if ($bookings->isNotEmpty())
            <h2 class="mt-8 text-sm font-semibold">Your riders</h2>
            <ul class="mt-2 flex flex-col gap-1">
                @foreach ($bookings as $booking)
                    <li>{{ $booking->rider->name }} <span class="text-sand-dim">(ticket {{ $booking->ticket_code }})</span></li>
                @endforeach
            </ul>
        @endif

        @if ($errors->has('choice'))
            <x-alert variant="error" class="mt-8" title="That didn’t work.">{{ $errors->first('choice') }}</x-alert>
        @endif

        <p class="mt-8 text-sand-dim">Choose until the session starts. If you don’t choose, your booking stays at the new time.</p>

        <div class="mt-6 flex flex-col gap-6">
            <form id="keep" method="POST" action="{{ $acceptUrl }}" class="flex flex-col gap-2 rounded-sm border border-line-strong p-5">
                @csrf
                <h2 class="font-semibold">Keep the new time</h2>
                <p class="text-sm text-sand-dim">Your tickets stay the same.</p>
                <div><x-button type="submit">Keep the new time</x-button></div>
            </form>

            @if ($bookings->isNotEmpty())
                <div class="flex flex-col gap-2 rounded-sm border border-line-strong p-5">
                    <h2 class="font-semibold">Move to another session</h2>
                    <p class="text-sm text-sand-dim">Pick another session with room for {{ $bookings->count() === 1 ? 'your rider' : 'all your riders' }}.</p>
                    <div><x-button :href="$moveUrl" variant="ghost">See other sessions</x-button></div>
                </div>

                @php($paid = $bookings->contains(fn ($b) => $b->credit_pack_id === null && $b->order_id !== null))
                <form id="cancel" method="POST" action="{{ $cancelUrl }}" class="flex flex-col gap-2 rounded-sm border border-line-strong p-5">
                    @csrf
                    <h2 class="font-semibold">{{ $paid ? 'Ask for a refund' : 'Cancel' }}</h2>
                    <p class="text-sm text-sand-dim">
                        @if ($paid)
                            We’ll be in touch about your refund. Your booking stays in place until then.
                        @else
                            The booking is cancelled and the credit goes back to your package.
                        @endif
                    </p>
                    <div><x-button type="submit" variant="ghost">{{ $paid ? 'Ask for a refund' : 'Cancel my booking' }}</x-button></div>
                </form>
            @endif
        </div>
    </section>
</x-layouts.app>
