{{--
    /book/details: a signed-out visitor's last booking step (guest checkout).
    The draft comes from the session; prices from the product row. A plain
    form, so the Turnstile widget works without extra scripts.
--}}
<x-layouts.app title="Your details" robots="noindex, nofollow">
    @if ($turnstileSiteKey)
        <x-slot:head>
            <script src="{{ \App\Support\Turnstile::SCRIPT_URL }}" async defer @nonce></script>
        </x-slot:head>
    @endif

    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="details-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Book a session</p>
        <h1 id="details-title" class="mt-2 text-display-lg">Your details.</h1>

        <dl class="mt-8 grid gap-4 rounded-sm border border-line-strong bg-earth-2 p-6 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-sand-dim">Session</dt>
                <dd class="font-semibold">{{ $product->name }}</dd>
            </div>
            <div>
                <dt class="text-sm text-sand-dim">When (Pakistan time)</dt>
                <dd class="font-semibold">{{ $slot ? $slot->label() : ($requestLabel ? $requestLabel.' (requested)' : 'You’ll choose your dates later') }}</dd>
            </div>
            @if ($riders !== [])
                <div class="sm:col-span-2">
                    <dt class="text-sm text-sand-dim">Riders</dt>
                    <dd class="font-semibold">{{ collect($riders)->pluck('name')->join(', ') }}</dd>
                </div>
            @endif
            <div class="border-t border-line pt-4 sm:col-span-2">
                <dt class="text-sm text-sand-dim">Total</dt>
                <dd class="font-display text-display-md font-black"><x-money :minor="$quote->totalMinor" /></dd>
            </div>
        </dl>
        <p class="mt-3 text-sm"><a href="{{ route('book', ['resume' => 1]) }}" class="underline underline-offset-4">Change something</a></p>

        <form method="POST" action="{{ route('book.details.store') }}" class="mt-8 flex flex-col gap-5" novalidate>
            @csrf
            <input type="hidden" name="draft" value="{{ $draftHash }}">

            {{-- Neutral refusals (the email, the security check, too many tries) come back on the email field. --}}
            <x-form.field name="name" label="Your name" required autocomplete="name" maxlength="120" />
            <x-form.field name="email" type="email" label="Email" required autocomplete="email" inputmode="email" maxlength="190" hint="Your tickets, receipt and a link to manage the booking go here." />
            <x-form.field name="email_confirmation" type="email" label="Email again" required autocomplete="email" inputmode="email" maxlength="190" />
            <x-form.field
                name="whatsapp"
                label="WhatsApp number"
                type="tel"
                required
                autocomplete="tel"
                maxlength="32"
                hint="For quick messages about your session. Pakistani numbers like 0334 4554500, others with the country code (+44 …)."
            />

            @if ($requestLabel)
                <x-form.field
                    name="note"
                    label="Anything we should know? (optional)"
                    type="textarea"
                    rows="3"
                    maxlength="{{ \App\Models\PrivateSessionRequest::NOTE_MAX_LENGTH }}"
                    :value="$note"
                    hint="For example the occasion, or riders’ experience."
                />
            @endif

            @if ($turnstileSiteKey)
                <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="dark" data-size="flexible"></div>
            @endif

            <div class="flex flex-col gap-3">
                <div>
                    <x-button type="submit">{{ $requestLabel ? 'Send request' : 'Continue to payment' }}</x-button>
                </div>
                @if ($requestLabel)
                    <p class="text-sm text-sand-dim">We’ll email you within {{ $requestRules->answerHours() }} hours. If we approve, you’ll have {{ $requestRules->payHours() }} hours to pay.</p>
                @else
                    <p class="text-sm text-sand-dim">Next you choose card or bank transfer. Gift vouchers and promo codes are applied at checkout.</p>
                @endif
                <p class="text-sm text-sand-dim">Already booked with us? <a href="{{ route('login') }}" class="underline underline-offset-4">Sign in</a> to use package or account credit.</p>
            </div>
        </form>
    </section>
</x-layouts.app>
