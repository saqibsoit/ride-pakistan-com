{{-- /events/{slug}/details: a signed-out visitor's details before paying for an event (guest checkout). --}}
<x-layouts.app title="Your details" robots="noindex, nofollow">
    @if ($turnstileSiteKey)
        <x-slot:head>
            <script src="{{ \App\Support\Turnstile::SCRIPT_URL }}" async defer @nonce></script>
        </x-slot:head>
    @endif

    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="details-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Book {{ $event->title }}</p>
        <h1 id="details-title" class="mt-2 text-display-lg">Your details.</h1>

        <dl class="mt-8 grid gap-4 rounded-sm border border-line-strong bg-earth-2 p-6 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-sand-dim">Event</dt>
                <dd class="font-semibold">{{ $event->title }}</dd>
            </div>
            <div>
                <dt class="text-sm text-sand-dim">When (Pakistan time)</dt>
                <dd class="font-semibold">{{ $slot->dateRangeLabel() }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-sm text-sand-dim">People</dt>
                <dd class="font-semibold">{{ collect($people)->pluck('name')->join(', ') }}</dd>
            </div>
            <div class="border-t border-line pt-4 sm:col-span-2">
                <dt class="text-sm text-sand-dim">{{ $balance > 0 ? 'Deposit to pay now (non-refundable)' : 'Total' }}</dt>
                <dd class="font-display text-display-md font-black"><x-money :minor="$payNow" /></dd>
                @if ($balance > 0)
                    <dd class="text-sm text-sand-dim">Balance later: <x-money :minor="$balance" /> (total <x-money :minor="$subtotal" />)</dd>
                @endif
            </div>
        </dl>
        <p class="mt-3 text-sm"><a href="{{ route('events.book', ['event' => $event->slug, 'resume' => 1]) }}" class="underline underline-offset-4">Change something</a></p>

        <form method="POST" action="{{ route('events.details.store', $event) }}" class="mt-8 flex flex-col gap-5" novalidate>
            @csrf
            <input type="hidden" name="draft" value="{{ $draftHash }}">

            <x-form.field name="name" label="Your name" required autocomplete="name" maxlength="120" />
            <x-form.field name="email" type="email" label="Email" required autocomplete="email" inputmode="email" maxlength="190" hint="Your tickets, receipt and a link to manage the booking go here." />
            <x-form.field name="email_confirmation" type="email" label="Email again" required autocomplete="email" inputmode="email" maxlength="190" />
            <x-form.field name="whatsapp" label="WhatsApp number" type="tel" required autocomplete="tel" maxlength="32"
                hint="For quick messages about the event. Pakistani numbers like 0334 4554500, others with the country code (+44 …)." />

            @if ($turnstileSiteKey)
                <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="dark" data-size="flexible"></div>
            @endif

            <div class="flex flex-col gap-3">
                <div><x-button type="submit">Continue to payment</x-button></div>
                <p class="text-sm text-sand-dim">Next you choose card or bank transfer. Gift vouchers and promo codes are applied at checkout.</p>
            </div>
        </form>
    </section>
</x-layouts.app>
