<x-layouts.account title="Move a session" :intro="'Currently '.$booking->slot->label().' for '.$booking->rider->name.'. Pakistan time.'">
    @if (! $canMove)
        <x-alert variant="warning" title="It’s too late to move this session.">
            Sessions can be moved up to {{ $cutoffHours }} hours before they start. You can still <a href="{{ route('account.bookings.cancel', $booking->ticket_code) }}" class="underline underline-offset-4">cancel it</a>, but the credit won’t come back.
        </x-alert>
    @else
        @if ($errors->has('slot'))
            <x-alert variant="error" title="That didn’t work.">{{ $errors->first('slot') }}</x-alert>
        @endif

        @if ($slots->isEmpty())
            <p class="text-sand-dim">No other slots are open before your package expires. <a href="{{ route('contact') }}" class="underline underline-offset-4">Message us</a> and we’ll help.</p>
        @else
            <form method="POST" action="{{ route('account.bookings.reschedule', $booking->ticket_code) }}" class="flex max-w-narrow flex-col gap-6">
                @csrf
                <fieldset class="flex flex-col gap-3">
                    <legend class="mb-2 text-sm font-semibold">New slot</legend>
                    @foreach ($slots as $slot)
                        <label class="flex cursor-pointer flex-wrap items-center justify-between gap-3 rounded-sm border border-line-strong p-4 has-checked:border-sand">
                            <span class="flex items-center gap-3">
                                <input type="radio" name="slot" value="{{ $slot->id }}" required class="size-5 accent-sand">
                                <time datetime="{{ $slot->starts_at->toIso8601String() }}" class="font-semibold">{{ $slot->label() }}</time>
                            </span>
                            <x-seats-left :seats="$slot->seatsFree()" />
                        </label>
                    @endforeach
                </fieldset>
                <div class="flex flex-wrap gap-3">
                    <x-button type="submit">Move my session</x-button>
                    <x-button :href="route('account.bookings')" variant="ghost">Keep it as it is</x-button>
                </div>
            </form>
        @endif
    @endif
</x-layouts.account>
