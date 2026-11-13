{{-- Move a credit booking from the manage page (guest checkout); same rules as the account page. --}}
<x-layouts.app title="Move a session" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="move-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Order {{ $order->reference }}</p>
        <h1 id="move-title" class="mt-2 text-display-lg">Move a session</h1>
        <p class="mt-2 text-sand-dim">Currently {{ $booking->slot->label() }} for {{ $booking->rider->name }}. Pakistan time.</p>

        <div class="mt-8">
            @if (! $canMove)
                <x-alert variant="warning" title="It’s too late to move this session.">
                    Sessions can be moved up to {{ $cutoffHours }} hours before they start. You can still <a href="{{ route('manage.cancel', [$order->reference, $booking->ticket_code]) }}" class="underline underline-offset-4">cancel it</a>, but the credit won’t come back.
                </x-alert>
            @else
                @if ($errors->has('slot'))
                    <x-alert variant="error" title="That didn’t work.">{{ $errors->first('slot') }}</x-alert>
                @endif

                @if ($slots->isEmpty())
                    <p class="text-sand-dim">No other slots are open before your package expires. <a href="{{ route('contact') }}" class="underline underline-offset-4">Message us</a> and we’ll help.</p>
                @else
                    <form method="POST" action="{{ route('manage.reschedule.store', [$order->reference, $booking->ticket_code]) }}" class="flex max-w-narrow flex-col gap-6">
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
                            <x-button :href="route('manage.show', $order->reference)" variant="ghost">Keep it as it is</x-button>
                        </div>
                    </form>
                @endif
            @endif
        </div>
    </section>
</x-layouts.app>
