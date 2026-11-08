{{-- /login: step 1, email address (+ Turnstile). Never cached or indexed. --}}
<x-layouts.app title="Sign in" description="Sign in to your Ride Pakistan account with a one-time code sent to your email." robots="noindex, nofollow">
    @if ($turnstileSiteKey)
        <x-slot:head>
            <script src="{{ \App\Support\Turnstile::SCRIPT_URL }}" async defer @nonce></script>
        </x-slot:head>
    @endif

    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="login-title">
        <h1 id="login-title" class="text-display-xl">Sign in.</h1>
        <p class="mt-6 max-w-prose text-lede text-sand-dim">
            Enter your email and we’ll send you a {{ (int) config('login.code.length') }}-digit code. No password needed. New here? Your account is created when you enter the code.
        </p>

        <div class="mt-10 flex flex-col gap-6">
            @if (session('status') === 'signed-out-everywhere')
                <x-alert variant="success" title="You’re signed out everywhere.">Sign in again on the devices you use.</x-alert>
            @elseif (session('status') === 'account-deleted')
                <x-alert variant="success" title="Your account is scheduled for deletion.">
                    You can change your mind by signing in again within {{ app(\App\Support\Settings::class)->int(\App\Enums\SettingKey::DeletionGraceDays) ?? 30 }} days.
                </x-alert>
            @endif

            @if ($errors->any())
                <x-alert variant="error" title="Please check the form.">{{ $errors->first() }}</x-alert>
            @endif

            <form method="POST" action="{{ route('login.request') }}" class="flex flex-col gap-6" novalidate>
                @csrf

                <x-form.field
                    name="email"
                    label="Email"
                    type="email"
                    required
                    autocomplete="email"
                    inputmode="email"
                    maxlength="190"
                    autofocus
                />

                @if ($turnstileSiteKey)
                    <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="dark" data-size="flexible"></div>
                @endif

                <div class="flex flex-wrap items-center gap-4">
                    <x-button type="submit">Email me a code</x-button>
                </div>
            </form>
        </div>
    </section>
</x-layouts.app>
