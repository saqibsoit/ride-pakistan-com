{{-- /login/code: step 2, the one-time code. The message is the same whether or not an account exists. --}}
<x-layouts.app title="Enter your code" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="verify-title">
        <h1 id="verify-title" class="text-display-xl">Check your email.</h1>
        <p class="mt-6 max-w-prose text-lede text-sand-dim">
            If <strong class="text-sand">{{ $email }}</strong> can sign in, we’ve sent it a {{ $length }}-digit code. It works once and expires in {{ $minutes }} minutes.
        </p>

        <div class="mt-10 flex flex-col gap-6">
            @if ($errors->any())
                <x-alert variant="error" title="That didn’t work.">{{ $errors->first() }}</x-alert>
            @endif

            <form method="POST" action="{{ route('login.verify.store') }}" class="flex flex-col gap-6" novalidate>
                @csrf

                <x-form.field
                    name="code"
                    label="Your code"
                    required
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="12"
                    autofocus
                    class="max-w-56 font-display text-display-xs tracking-label"
                />

                <div class="flex flex-wrap items-center gap-4">
                    <x-button type="submit">Sign in</x-button>
                    <a href="{{ route('login') }}" class="text-sm text-sand-dim underline underline-offset-4 hover:text-sand">Use a different email or get a new code</a>
                </div>
            </form>

            <p class="text-sm text-sand-dim">No email? Check your spam folder, then request a new code.</p>
        </div>
    </section>
</x-layouts.app>
