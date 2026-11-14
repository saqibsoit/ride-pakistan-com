{{-- /contact: lead form (stored by App\Actions\Leads\CaptureLead). --}}
@php
    $settings = app(\App\Support\Settings::class);
    $email = $settings->string(\App\Enums\SettingKey::Email);
@endphp

<x-layouts.app :seo="$seo">
    <x-page-header
        title="Get in touch."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Contact']]"
        :answer="'The fastest way to reach Ride Pakistan is a DM on Instagram. You can also email '.$email.' or use the form below and we’ll get back to you. We ride in '.$facts->location().', '.$facts->scheduleText().'.'"
    >
        <x-button :href="$settings->string(\App\Enums\SettingKey::InstagramDmUrl)" external>Message us on Instagram</x-button>
        <x-button :href="'mailto:'.$email" variant="ghost">Email {{ $email }}</x-button>
    </x-page-header>

    <section id="contact-form" class="wrap max-w-narrow pb-section" aria-labelledby="form-title" tabindex="-1">
        <h2 id="form-title" class="mb-8 text-display-md">Send a message.</h2>

        @if (session('status') === 'lead-sent')
            <x-alert variant="success" title="Thanks, your message is on its way." class="mb-8">
                We’ll get back to you by phone or email.
                <x-spam-hint />
            </x-alert>
        @endif

        @if ($errors->any())
            <x-alert variant="error" title="Please check the form." class="mb-8">
                {{ $errors->count() === 1 ? 'One field needs' : $errors->count().' fields need' }} attention.
            </x-alert>
        @endif

        <x-lead-form
            :action="route('contact.store')"
            :attribution="$attribution"
            :selected-interest="$selectedInterest"
            :turnstile-site-key="$turnstileSiteKey"
        />
    </section>

    @if ($facts->mapUrl() !== null)
        <section id="where" class="wrap max-w-narrow pb-section" aria-labelledby="where-title">
            <x-venue-map />
        </section>
    @endif
</x-layouts.app>
