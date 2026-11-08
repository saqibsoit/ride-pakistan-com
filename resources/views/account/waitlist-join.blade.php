<x-layouts.account title="Join the waitlist" :intro="$slot->label().'. Pakistan time.'">
    @if ($existing)
        <x-alert variant="info" title="You’re already on this waitlist.">
            You’re number {{ $existing->position() }} in line for {{ $existing->seats }} {{ $existing->seats === 1 ? 'rider' : 'riders' }}.
            <a href="{{ route('account.waitlist') }}" class="underline underline-offset-4">See your waitlists</a>.
        </x-alert>
    @else
        @if ($free > 0)
            <x-alert variant="info" title="{{ $free }} {{ $free === 1 ? 'seat is' : 'seats are' }} free right now.">
                If that’s enough for your group, <a href="{{ route('book', ['slot' => $slot->id, 'riders' => min($free, $riders)]) }}" class="underline underline-offset-4">book now</a> instead.
            </x-alert>
        @endif

        <div class="flex max-w-prose flex-col gap-3 text-sand-dim">
            <p>When seats free up we email the first person in line whose group fits. We hold the seats for you for {{ $offerHours }} {{ $offerHours === 1 ? 'hour' : 'hours' }}; if you don’t book in that time, the offer goes to the next person.</p>
            <p>Joining is free and you can leave at any time.</p>
        </div>

        <form method="POST" action="{{ route('account.waitlist.store', $slot) }}" class="flex max-w-narrow flex-col gap-5">
            @csrf
            <x-form.field name="seats" label="How many riders?" type="select" required>
                @for ($i = 1; $i <= $maxSeats; $i++)
                    <option value="{{ $i }}" @selected((int) old('seats', $riders) === $i)>{{ $i }} {{ $i === 1 ? 'rider' : 'riders' }}</option>
                @endfor
            </x-form.field>
            <div>
                <x-button type="submit">Join the waitlist</x-button>
            </div>
        </form>
    @endif
</x-layouts.account>
