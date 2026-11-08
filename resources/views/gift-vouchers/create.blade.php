{{-- /gift-vouchers/buy (milestone 10e): choose a session or an amount, the recipient and when to send. Works without JavaScript: the server ignores the option not chosen. --}}
@php
    use App\Support\Money;
    $kind = old('kind', $products->isNotEmpty() ? 'product' : 'amount');
    $send = old('send', 'now');
@endphp

<x-layouts.app title="Buy a gift voucher" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="buy-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Gift voucher</p>
        <h1 id="buy-title" class="mt-3 text-display-xl">Give a ride.</h1>
        <p class="mt-6 max-w-prose text-lede text-sand-dim">Valid for {{ $months }} months on any session or package. You'll pay on the next step.</p>

        <form method="POST" action="{{ route('gift-vouchers.store') }}" class="mt-10 flex flex-col gap-10" novalidate>
            @csrf

            @if ($errors->has('voucher'))
                <x-alert variant="error" title="We couldn't create the voucher.">{{ $errors->first('voucher') }}</x-alert>
            @endif

            <fieldset class="flex flex-col gap-5">
                <legend class="mb-4 text-display-xs">What is it for?</legend>

                @if ($products->isNotEmpty())
                    <div class="flex flex-col gap-3 rounded-sm border border-line-strong p-5">
                        <div class="flex items-center gap-3">
                            <input type="radio" id="kind-product" name="kind" value="product" @checked($kind === 'product') class="size-5 accent-sand">
                            <label for="kind-product" class="font-semibold">A session or package</label>
                        </div>
                        <x-form.field name="product_id" label="Session" type="select" hint="The voucher holds the price shown, in rupees." required>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>{{ $product->name }} ({{ Money::format($product->price_minor) }})</option>
                            @endforeach
                        </x-form.field>
                    </div>
                @endif

                <div class="flex flex-col gap-3 rounded-sm border border-line-strong p-5">
                    <div class="flex items-center gap-3">
                        <input type="radio" id="kind-amount" name="kind" value="amount" @checked($kind === 'amount') class="size-5 accent-sand">
                        <label for="kind-amount" class="font-semibold">An amount</label>
                    </div>
                    <x-form.field name="amount_pkr" label="Amount in rupees" type="number" inputmode="numeric"
                        :hint="'From '.Money::format($minMinor).' to '.Money::format($maxMinor).', whole rupees.'"
                        :min="intdiv($minMinor, 100)" :max="intdiv($maxMinor, 100)" step="1" required />
                </div>
                @error('kind')<p class="text-sm text-error"><span class="sr-only">Error: </span>{{ $message }}</p>@enderror
            </fieldset>

            <fieldset class="flex flex-col gap-5">
                <legend class="mb-4 text-display-xs">Who is it for?</legend>
                <x-form.field name="recipient_name" label="Their name" required autocomplete="off" maxlength="120" />
                <x-form.field name="recipient_email" label="Their email" type="email" hint="We send the voucher here." required autocomplete="off" maxlength="190" />
                <x-form.field name="message" label="Your message" type="textarea" rows="4" maxlength="500" hint="Up to 500 characters, shown on the voucher." />
            </fieldset>

            <fieldset class="flex flex-col gap-5">
                <legend class="mb-4 text-display-xs">When should we send it?</legend>
                <div class="flex items-center gap-3">
                    <input type="radio" id="send-now" name="send" value="now" @checked($send === 'now') class="size-5 accent-sand">
                    <label for="send-now">As soon as the payment is confirmed</label>
                </div>
                <div class="flex flex-col gap-3 rounded-sm border border-line-strong p-5">
                    <div class="flex items-center gap-3">
                        <input type="radio" id="send-later" name="send" value="later" @checked($send === 'later') class="size-5 accent-sand">
                        <label for="send-later">On a date</label>
                    </div>
                    <x-form.field name="send_on" label="Send date" type="date"
                        :hint="'At 9 am Pakistan time, up to '.$maxDays.' days ahead.'"
                        :min="$today->format('Y-m-d')" :max="$today->addDays($maxDays)->format('Y-m-d')" required />
                </div>
            </fieldset>

            <div><x-button type="submit">Continue to payment</x-button></div>
        </form>
    </section>
</x-layouts.app>
