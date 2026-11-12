{{-- /manage/{reference}/book: book with the order's package credits without signing in (guest checkout). --}}
<x-layouts.app title="Book with your credits" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="book-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Order {{ $order->reference }}</p>
        <h1 id="book-title" class="mt-2 text-display-lg">Book with your credits</h1>
        <p class="mt-2 text-sm"><a href="{{ route('manage.show', $order->reference) }}" class="underline underline-offset-4">Back to your booking</a></p>

        <div class="mt-8">
            <livewire:manage.pack-booking :reference="$order->reference" />
        </div>
    </section>
</x-layouts.app>
