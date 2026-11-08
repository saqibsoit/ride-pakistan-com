{{-- /book holding page until the booking flow ships (milestone 5). noindex. --}}
@php
    $settings = app(\App\Support\Settings::class);
@endphp

<x-layouts.app :seo="$seo">
    <x-page-header
        title="Book a session."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Book']]"
        :answer="'Online booking is opening soon. Until then, send us a DM with your preferred date, slot and number of riders, and we’ll confirm your place. Sessions run '.$facts->scheduleText().' in '.$facts->location().'.'"
    >
        <x-button :href="$settings->string(\App\Enums\SettingKey::InstagramDmUrl)" external>Message us on Instagram</x-button>
        <x-button :href="route('contact', $product ? ['interest' => $product->type->value] : [])" variant="ghost">Use the contact form</x-button>
    </x-page-header>

    <section class="wrap pb-section" aria-labelledby="choose-title">
        @if ($product)
            <x-alert class="mb-10 max-w-prose" title="You chose: {{ $product->name }}">
                <x-money :minor="$product->price_minor" /> {{ $product->isPackage() ? 'for '.$product->sessions_count.' sessions' : 'per rider' }}. Mention it in your message.
            </x-alert>
        @endif

        <h2 id="choose-title" class="mb-8 text-display-md">Our sessions.</h2>
        <div class="border-t border-sand">
            @foreach ($products as $item)
                <x-price-row :title="$item->name" :amount-minor="$item->price_minor" :caption="$item->isPackage() ? $item->sessions_count.' sessions' : 'per rider'" :featured="$item->is_featured">
                    {{ $item->short_description }}
                    <a href="{{ route('sessions.show', $item) }}" class="mt-2 block font-semibold text-sand underline underline-offset-4">Details<span class="sr-only"> about the {{ $item->name }}</span></a>
                </x-price-row>
            @endforeach
        </div>
    </section>
</x-layouts.app>
