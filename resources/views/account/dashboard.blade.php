@php
    $tz = config('app.display_timezone');
@endphp

<x-layouts.account :title="$user->name ? 'Hello, '.\Illuminate\Support\Str::of($user->name)->before(' ').'.' : 'Your account'">
    @if (session('status') === 'account-restored')
        <x-alert variant="success" title="Welcome back.">Your account is no longer scheduled for deletion.</x-alert>
    @endif

    @if (! $user->name)
        <x-alert variant="info" title="Tell us your name.">
            Add your name and phone number on your <a href="{{ route('account.profile') }}" class="underline underline-offset-4">profile</a> so we know who’s coming.
        </x-alert>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        <section aria-labelledby="next-session" class="flex flex-col gap-4 rounded-sm border border-line bg-earth-2 p-6">
            <h2 id="next-session" class="text-display-xs">Next session</h2>
            @if ($nextBooking)
                <p class="text-lede text-sand">
                    <time datetime="{{ $nextBooking->slot->starts_at->toIso8601String() }}">{{ $nextBooking->slot->label() }}</time>
                </p>
                <p class="text-sm text-sand-dim">Rider: {{ $nextBooking->rider->name }}</p>
                <div>
                    <x-button :href="route('account.tickets.show', $nextBooking->ticket_code)" variant="ghost" size="sm">View ticket</x-button>
                </div>
            @else
                <p class="text-sand-dim">Nothing booked yet.</p>
                <div>
                    <x-button :href="route('schedule')" size="sm">See the schedule</x-button>
                </div>
            @endif
        </section>

        <section aria-labelledby="credits-left" class="flex flex-col gap-4 rounded-sm border border-line bg-earth-2 p-6">
            <h2 id="credits-left" class="text-display-xs">Credits left</h2>
            @if ($creditsLeft > 0)
                <p class="font-display text-display-md text-sand">{{ $creditsLeft }}</p>
                <ul class="flex flex-col gap-1 text-sm text-sand-dim">
                    @foreach ($packs as $pack)
                        <li>
                            {{ $pack->creditsRemaining() }} of {{ $pack->credits_total }} left, use by
                            <time datetime="{{ $pack->expires_at->toIso8601String() }}">{{ $pack->expires_at->setTimezone($tz)->format('j F Y') }}</time>
                        </li>
                    @endforeach
                </ul>
                <div>
                    <x-button :href="route('account.credits')" variant="ghost" size="sm">Credit details</x-button>
                </div>
            @else
                <p class="text-sand-dim">No package credits yet.</p>
                <div>
                    <x-button :href="route('prices')" variant="ghost" size="sm">See packages</x-button>
                </div>
            @endif
        </section>
    </div>

    @if ($openOrders > 0)
        <x-alert variant="warning" title="{{ $openOrders === 1 ? 'You have an order waiting for payment.' : 'You have '.$openOrders.' orders waiting for payment.' }}">
            See <a href="{{ route('account.orders') }}" class="underline underline-offset-4">your orders</a>.
        </x-alert>
    @endif
</x-layouts.account>
