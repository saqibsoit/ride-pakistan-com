{{-- /schedule-change/{notice}/move: pick another session after a time change (prompt 15A). --}}
<x-layouts.app title="Move to another session" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="move-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Schedule change</p>
        <h1 id="move-title" class="mt-2 text-display-lg">Move to another session</h1>
        <p class="mt-4 text-lede text-sand-dim">Your session is now {{ $notice->newLabel() }}. Pick another one below (Pakistan time).</p>

        @if ($errors->has('slot_id'))
            <x-alert variant="error" class="mt-8" title="That didn’t work.">{{ $errors->first('slot_id') }}</x-alert>
        @endif

        @if (empty($slots))
            <p class="mt-8 text-sand-dim">No other session has room right now. <a href="{{ route('contact') }}" class="underline underline-offset-4">Message us</a> and we’ll help, or go back and keep the new time.</p>
            <p class="mt-4"><a href="{{ $backUrl }}" class="underline underline-offset-4 hover:text-sand">Back</a></p>
        @else
            <form method="POST" action="{{ $action }}" class="mt-8 flex flex-col gap-6">
                @csrf
                <fieldset class="flex flex-col gap-3">
                    <legend class="mb-2 text-sm font-semibold">New session for {{ $bookings->pluck('rider.name')->join(', ', ' and ') }}</legend>
                    @foreach ($slots as $slot)
                        <label class="flex cursor-pointer flex-wrap items-center justify-between gap-3 rounded-sm border border-line-strong p-4 has-checked:border-sand">
                            <span class="flex items-center gap-3">
                                <input type="radio" name="slot_id" value="{{ $slot->id }}" required class="size-5 accent-sand">
                                <time datetime="{{ $slot->starts_at->toIso8601String() }}" class="font-semibold">{{ $slot->label() }}</time>
                            </span>
                            <x-seats-left :seats="$slot->seatsFree()" />
                        </label>
                    @endforeach
                </fieldset>
                <div class="flex flex-wrap gap-3">
                    <x-button type="submit">Move my booking</x-button>
                    <x-button :href="$backUrl" variant="ghost">Back</x-button>
                </div>
            </form>
        @endif
    </section>
</x-layouts.app>
