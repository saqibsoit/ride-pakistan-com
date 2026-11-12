{{--
    /manage: ask for a new "Manage your booking" link (guest checkout). Also
    the page for a link that doesn't work: the same page for a tampered,
    stopped or expired link and for a booking nobody may open here, so it
    tells nothing about the order.
--}}
<x-layouts.app title="Manage your booking" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="link-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Manage your booking</p>
        <h1 id="link-title" class="mt-2 text-display-lg">{{ $closed ? 'This link doesn’t work any more' : 'Get a link to your booking' }}</h1>

        @if (session('status') === 'sent')
            <x-alert variant="success" class="mt-8" title="Check your email.">
                If that email and reference match a booking, we’ve sent a new link.
                <x-spam-hint />
            </x-alert>
        @elseif (session('status') === 'revoked')
            <x-alert variant="success" class="mt-8" title="The old links are stopped.">
                Nobody can open your booking with them any more. We’ve emailed a new link to the address you booked with.
                <x-spam-hint />
            </x-alert>
        @elseif ($closed)
            <p class="mt-6 text-sand-dim">It may have expired (links work until 7 days after your last session) or been replaced by a newer one. Ask for a new link below, or sign in to see all your bookings.</p>
        @endif

        <form method="POST" action="{{ route('manage.link.send') }}" class="mt-8 flex max-w-narrow flex-col gap-5">
            @csrf
            <x-form.field name="email" type="email" label="Email you booked with" required autocomplete="email" />
            <x-form.field name="reference" label="Order reference" hint="It starts with RP-, for example RP-7KD2QX. It’s in your booking emails." required autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="20" />
            <div class="flex flex-wrap gap-3">
                <x-button type="submit">Email me a link</x-button>
                <x-button :href="route('login')" variant="ghost">Sign in instead</x-button>
            </div>
        </form>
    </section>
</x-layouts.app>
