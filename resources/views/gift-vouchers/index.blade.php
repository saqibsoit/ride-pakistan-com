{{-- /gift-vouchers (milestone 10e): what a gift voucher is, what it can be for, and how it is delivered. Cached; buying needs sign-in. --}}
@php
    use App\Support\Money;
    $answer = 'A Ride Pakistan gift voucher pays for horse riding in Islamabad. Choose a session or package, or any amount from '
        .Money::format($minMinor).' to '.Money::format($maxMinor).'. We email it to the person you choose, straight away or on a date you pick, with a printable voucher. It is valid for '
        .$months.' months and can be used on any session or package; anything not spent stays on the voucher.';
@endphp

<x-layouts.app :seo="$seo">
    <x-page-header
        title="Gift vouchers."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Gift vouchers']]"
        :answer="$answer"
    >
        <x-button :href="route('gift-vouchers.create')">Buy a gift voucher</x-button>
    </x-page-header>

    @if ($products->isNotEmpty())
        <section class="wrap pb-section" aria-labelledby="give-title">
            <x-section-heading id="give-title" title="Give a session." size="lg" lede="Or enter any amount when you buy." />
            <div class="mt-4 border-t border-sand">
                @foreach ($products as $product)
                    <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-line py-5">
                        <h3 class="text-display-xs">{{ $product->name }}</h3>
                        <p class="font-semibold"><x-money :minor="$product->price_minor" /></p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="bg-earth-2 py-section" aria-labelledby="how-title">
        <div class="wrap">
            <x-section-heading id="how-title" title="How it works." size="lg" />
            <ol class="mt-10 grid gap-8 md:grid-cols-3">
                <li class="flex flex-col gap-2 border-t border-line pt-6">
                    <h3 class="text-display-xs">1. Choose</h3>
                    <p class="text-sand-dim">A session, a package or an amount, the recipient's name and email, and a message if you like.</p>
                </li>
                <li class="flex flex-col gap-2 border-t border-line pt-6">
                    <h3 class="text-display-xs">2. Pay</h3>
                    <p class="text-sand-dim">By card, or by bank transfer with your reference. The voucher is issued once we've confirmed the payment.</p>
                </li>
                <li class="flex flex-col gap-2 border-t border-line pt-6">
                    <h3 class="text-display-xs">3. We send it</h3>
                    <p class="text-sand-dim">Straight away or at 9 am (Pakistan time) on the day you choose, with a unique code and a voucher to print.</p>
                </li>
            </ol>
        </div>
    </section>

    <section class="wrap py-section" aria-labelledby="terms-title">
        <x-section-heading id="terms-title" title="Good to know." size="lg" />
        <ul class="mt-8 flex max-w-prose list-disc flex-col gap-3 pl-5 text-sand-dim">
            <li>Valid for {{ $months }} months from the day the payment is confirmed.</li>
            <li>Use it on any session or package. If the booking costs less, the rest stays on the voucher; if it costs more, pay the difference.</li>
            <li>A voucher can be combined with one promo code.</li>
            <li>Vouchers can't be exchanged for cash.</li>
        </ul>
        <div class="mt-10"><x-button :href="route('gift-vouchers.create')">Buy a gift voucher</x-button></div>
    </section>
</x-layouts.app>
