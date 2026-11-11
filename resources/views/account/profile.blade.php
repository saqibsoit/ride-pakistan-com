<x-layouts.account title="Profile">
    @if (session('status') === 'profile-saved')
        <x-alert variant="success" title="Profile saved." />
    @endif

    @if ($errors->any())
        <x-alert variant="error" title="Please check the form.">
            {{ $errors->count() === 1 ? 'One field needs' : $errors->count().' fields need' }} attention.
        </x-alert>
    @endif

    <form method="POST" action="{{ route('account.profile.update') }}" class="flex max-w-narrow flex-col gap-6" novalidate>
        @csrf
        @method('PUT')

        <x-form.field name="name" label="Your name" required autocomplete="name" maxlength="120" :value="$user->name" />

        <div class="flex flex-col gap-2">
            <p class="text-sm font-semibold text-sand">Email</p>
            <p class="text-sand">{{ $user->email }}</p>
            <p class="text-sm text-sand-dim">You sign in with this address. To change it, <a href="{{ route('contact') }}" class="underline underline-offset-4 hover:text-sand">contact us</a>.</p>
        </div>

        <x-form.field
            name="phone"
            label="Phone"
            type="tel"
            autocomplete="tel"
            inputmode="tel"
            maxlength="32"
            hint="Pakistani numbers as 0300 1234567; other countries with the country code, like +44 7700 900123."
            :value="$user->phone"
        />

        <x-form.field
            name="whatsapp_phone"
            label="WhatsApp number"
            type="tel"
            autocomplete="tel"
            inputmode="tel"
            maxlength="32"
            hint="For quick messages about your sessions. We ask for it when you book if it’s empty."
            :value="$user->whatsapp_phone"
        />

        <div class="flex items-start gap-3">
            <input type="hidden" name="marketing_consent" value="0">
            <input
                type="checkbox"
                id="marketing_consent"
                name="marketing_consent"
                value="1"
                @checked(old('marketing_consent', $user->marketing_consent ? '1' : '0') === '1')
                aria-describedby="marketing_consent-hint"
                class="mt-1 size-5 shrink-0 rounded-sm border-line-strong bg-earth-2 accent-sand"
            >
            <div class="flex flex-col gap-1">
                <label for="marketing_consent" class="text-sm font-semibold text-sand">Send me news and offers</label>
                <p id="marketing_consent-hint" class="text-sm text-sand-dim">
                    Occasional emails about new sessions and expeditions. Unsubscribe any time.
                    @if ($user->marketing_consent_at)
                        Last changed <time datetime="{{ $user->marketing_consent_at->toIso8601String() }}">{{ $user->marketing_consent_at->setTimezone(config('app.display_timezone'))->format('j F Y') }}</time>.
                    @endif
                </p>
            </div>
        </div>

        <div>
            <x-button type="submit">Save profile</x-button>
        </div>
    </form>
</x-layouts.account>
