{{-- /book: the booking flow (Livewire). noindex, not cached. --}}
<x-layouts.app :seo="$seo" livewire>
    <x-page-header
        title="Book a session."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Book']]"
        :answer="'Sessions run '.$facts->scheduleText().' in '.$facts->location().'. Choose a session, a date and your riders; prices are in Pakistani rupees and confirmed before you pay.'"
    />

    <section class="wrap max-w-narrow pb-section" aria-label="Booking">
        <livewire:book.booking-flow />
    </section>
</x-layouts.app>
