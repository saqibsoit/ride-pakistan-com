{{-- /events/{slug}/book: the event booking form (Livewire). noindex, not cached. --}}
<x-layouts.app :seo="$seo" livewire>
    <x-page-header
        :title="'Book '.$event->title.'.'"
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Events', 'url' => route('events.index')], ['label' => $event->title, 'url' => route('events.show', $event)], ['label' => 'Book']]"
        :answer="'Choose a date and how many people, give each person’s name, then pay '.($event->takesDeposit() ? 'a '.$event->deposit_percent.'% deposit' : 'in full').' by card or bank transfer. Prices are in Pakistani rupees.'"
    />

    <section class="wrap max-w-narrow pb-section" aria-label="Booking">
        @if (session('booking_notice'))
            <x-alert variant="warning" class="mb-8">{{ session('booking_notice') }}</x-alert>
        @endif
        <livewire:events.event-booking :event="$event" :resume="$resume" />
    </section>
</x-layouts.app>
