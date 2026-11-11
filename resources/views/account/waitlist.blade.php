<x-layouts.account title="Waitlist" intro="Full sessions you’re waiting for. All times are Pakistan time.">
    @php($flash = [
        'waitlist-joined' => 'You’re on the waitlist. We’ll email you as soon as seats free up.',
        'waitlist-left' => 'You’ve left the waitlist.',
    ][session('status')] ?? null)
    @if ($flash)
        <x-alert variant="success">{{ $flash }}@if (session('status') === 'waitlist-joined')<x-spam-hint />@endif</x-alert>
    @endif
    @if ($errors->has('waitlist'))
        <x-alert variant="error">{{ $errors->first('waitlist') }}</x-alert>
    @endif

    @if ($entries->isEmpty())
        <p class="text-sand-dim">You’re not waiting for any sessions. When a session on the <a href="{{ route('schedule') }}" class="underline underline-offset-4 hover:text-sand">schedule</a> is full, you can join its waitlist.</p>
    @else
        <ul class="flex flex-col gap-3">
            @foreach ($entries as $entry)
                @php($offer = $entry->status === \App\Enums\WaitlistStatus::Offered && $entry->offer_expires_at?->isFuture())
                <li class="flex flex-wrap items-center justify-between gap-4 rounded-sm border border-line bg-earth-2 p-5">
                    <div class="flex flex-col gap-1">
                        <p class="font-semibold text-sand"><time datetime="{{ $entry->slot->starts_at->toIso8601String() }}">{{ $entry->slot->label() }}</time></p>
                        <p class="text-sm text-sand-dim">
                            {{ $entry->seats }} {{ $entry->seats === 1 ? 'rider' : 'riders' }}
                            @if ($entry->status === \App\Enums\WaitlistStatus::Waiting)
                                · number {{ $entry->position() }} in line
                            @elseif ($offer)
                                · held for you until <time datetime="{{ $entry->offer_expires_at->toIso8601String() }}">{{ $entry->offer_expires_at->setTimezone(config('app.display_timezone'))->format('D j M, H:i') }}</time>
                            @elseif ($entry->closed_reason)
                                · {{ $entry->closed_reason }}
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-badge :variant="$offer ? 'success' : 'neutral'">{{ $entry->status->label() }}</x-badge>
                        @if ($offer)
                            <x-button :href="$bookUrl($entry)" size="sm">Book now<span class="sr-only"> {{ $entry->slot->label() }}</span></x-button>
                        @endif
                        @if ($entry->status->isActive())
                            <form method="POST" action="{{ route('account.waitlist.destroy', $entry->id) }}">
                                @csrf
                                @method('DELETE')
                                <x-button type="submit" variant="ghost" size="sm">Leave<span class="sr-only"> the waitlist for {{ $entry->slot->label() }}</span></x-button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.account>
