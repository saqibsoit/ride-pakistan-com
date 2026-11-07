{{-- /contact: lead form (stored by App\Actions\Leads\CaptureLead). --}}
@php
    $settings = app(\App\Support\Settings::class);
    $email = $settings->string(\App\Enums\SettingKey::Email);
@endphp

<x-layouts.app :seo="$seo">
    <x-page-header
        title="Get in touch."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Contact']]"
        :answer="'The fastest way to reach Ride Pakistan is a DM on Instagram. You can also email '.$email.' or use the form below, and we’ll reply within a day. We ride in '.$facts->location().', '.$facts->scheduleText().'.'"
    >
        <x-button :href="$settings->string(\App\Enums\SettingKey::InstagramDmUrl)" external>Message us on Instagram</x-button>
        <x-button :href="'mailto:'.$email" variant="ghost">Email {{ $email }}</x-button>
    </x-page-header>

    <section id="contact-form" class="wrap max-w-narrow pb-section" aria-labelledby="form-title" tabindex="-1">
        <h2 id="form-title" class="mb-8 text-display-md">Send a message.</h2>

        @if (session('status') === 'lead-sent')
            <x-alert variant="success" title="Thanks, your message is on its way." class="mb-8">
                We’ll get back to you within a day, by phone or email.
            </x-alert>
        @endif

        @if ($errors->any())
            <x-alert variant="error" title="Please check the form." class="mb-8">
                {{ $errors->count() === 1 ? 'One field needs' : $errors->count().' fields need' }} attention.
            </x-alert>
        @endif

        <form method="POST" action="{{ route('contact.store') }}" class="flex flex-col gap-6" novalidate>
            @csrf

            <x-form.field name="name" label="Your name" required autocomplete="name" maxlength="120" />

            <div class="grid gap-6 md:grid-cols-2">
                <x-form.field name="phone" label="Phone or WhatsApp" type="tel" autocomplete="tel" inputmode="tel" hint="Phone or email: we need one to reply." maxlength="32" />
                <x-form.field name="email" label="Email" type="email" autocomplete="email" maxlength="190" />
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <x-form.field name="interest" label="I’m interested in" type="select">
                    <option value="">Choose one (optional)</option>
                    @foreach ($interests as $interest)
                        <option value="{{ $interest->value }}" @selected(old('interest', $selectedInterest?->value) === $interest->value)>{{ $interest->label() }}</option>
                    @endforeach
                </x-form.field>
                <x-form.field name="preferred_date" label="Preferred date" type="date" :min="now((string) config('app.display_timezone'))->format('Y-m-d')" hint="Optional." />
            </div>

            <x-form.field name="message" label="Message" type="textarea" rows="5" maxlength="2000" />

            {{-- Honeypot: hidden from people and assistive tech; bots fill it. --}}
            <div class="hidden" aria-hidden="true">
                <label for="website">Leave this empty</label>
                <input type="text" id="website" name="{{ \App\Http\Requests\StoreLeadRequest::HONEYPOT }}" tabindex="-1" autocomplete="off">
            </div>

            @foreach ($attribution as $field => $value)
                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
            @endforeach

            <div class="flex flex-wrap items-center gap-4">
                <x-button type="submit">Send message</x-button>
                <p class="text-sm text-sand-dim">We only use your details to reply to you.</p>
            </div>
        </form>
    </section>
</x-layouts.app>
