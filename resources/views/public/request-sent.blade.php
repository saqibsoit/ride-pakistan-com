{{-- After a guest private session request (guest checkout). The same page whether or not it could be sent. --}}
<x-layouts.app title="Request sent" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="sent-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Private session</p>
        <h1 id="sent-title" class="mt-2 text-display-lg">Request sent.</h1>
        <x-alert variant="success" class="mt-8" title="Check your email.">
            We’ve emailed {{ $email }} with what happens next and a link to follow your request. If we approve it, that email has the link to pay.
            <x-spam-hint />
        </x-alert>
        <div class="mt-8 flex flex-wrap gap-3">
            <x-button :href="route('home')" variant="ghost">Back to the home page</x-button>
        </div>
    </section>
</x-layouts.app>
