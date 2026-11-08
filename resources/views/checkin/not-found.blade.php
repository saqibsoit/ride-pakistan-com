{{-- /checkin/{code} for a code that matches no ticket. --}}
<x-layouts.app title="Ticket not found" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-8 pb-section" aria-labelledby="notfound-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Gate check-in</p>
        <h1 id="notfound-title" class="mt-2 text-display-lg">Ticket not found.</h1>
        <div class="mt-6">
            <x-alert variant="error" title="No ticket with this code">
                Check the code ({{ \Illuminate\Support\Str::limit($code, 20) }}) with the rider. Tickets are in their account and in the confirmation email.
            </x-alert>
        </div>
    </section>
</x-layouts.app>
