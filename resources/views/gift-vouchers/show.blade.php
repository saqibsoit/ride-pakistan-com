{{-- /gift-vouchers/{reference} (milestone 10e): purchase summary and the two ways to pay. --}}
@php
    use App\Enums\VoucherOrderStatus;
    $tz = config('app.display_timezone');
    $status = $order->status;
@endphp

<x-layouts.app title="Gift voucher {{ $order->reference }}" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="checkout-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Gift voucher {{ $order->reference }}</p>
        <h1 id="checkout-title" class="mt-3 text-display-xl">{{ $status->isUnpaid() ? 'Almost there.' : 'Your gift voucher.' }}</h1>

        <div class="mt-10 flex flex-col gap-6">
            @if ($status === VoucherOrderStatus::Paid)
                <x-alert variant="success" title="Payment received.">
                    {{ $order->send_on ? 'We\'ll email the voucher to '.$order->recipient_name.' on '.$order->send_on->format('j F Y').' at 9 am (Pakistan time).' : 'We\'ve emailed the voucher to '.$order->recipient_name.'.' }}
                </x-alert>
            @elseif ($status === VoucherOrderStatus::Expired)
                <x-alert variant="warning" title="This purchase has expired.">It wasn't paid in time. <a href="{{ route('gift-vouchers.create') }}" class="underline underline-offset-4">Start again</a>.</x-alert>
            @elseif (! $status->isUnpaid())
                <x-alert variant="warning" title="This purchase is {{ strtolower($status->label()) }}.">{{ $order->rejection_reason }}</x-alert>
            @endif

            <dl class="grid gap-4 rounded-sm border border-line-strong bg-earth-2 p-6 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-sand-dim">Voucher</dt>
                    <dd class="font-semibold">{{ $order->label() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">Status</dt>
                    <dd class="font-semibold">{{ $status->label() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">For</dt>
                    <dd class="font-semibold break-all">{{ $order->recipient_name }} ({{ $order->recipient_email }})</dd>
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">Sent</dt>
                    <dd class="font-semibold">{{ $order->send_on ? $order->send_on->format('j F Y').', 9 am (Pakistan time)' : 'As soon as the payment is confirmed' }}</dd>
                </div>
                <div class="border-t border-line pt-4 sm:col-span-2">
                    <dt class="text-sm text-sand-dim">Total</dt>
                    <dd class="font-display text-display-md font-black"><x-money :minor="$order->amount_minor" :currency="$order->currency" /></dd>
                </div>
            </dl>

            @if (session('checkout_error'))
                <x-alert variant="error" title="That didn't work.">{{ session('checkout_error') }}</x-alert>
            @endif

            @if ($status === VoucherOrderStatus::PendingPayment)
                <section aria-labelledby="pay-title" class="flex flex-col gap-5">
                    <h2 id="pay-title" class="text-display-xs">How would you like to pay?</h2>

                    @if ($cardFailed)
                        <x-alert variant="warning" title="Your card payment didn't go through.">Nothing was charged. Try a card again, or pay by bank transfer with the same reference.</x-alert>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <form method="POST" action="{{ route('gift-vouchers.card', $order->reference) }}" class="flex flex-col gap-3 rounded-sm border border-line-strong p-6">
                            @csrf
                            <h3 class="font-semibold">Pay by card</h3>
                            <p class="text-sm text-sand-dim">Secure payment on Stripe's checkout page, in Pakistani rupees. The voucher is issued as soon as the payment clears.</p>
                            <x-button type="submit">{{ $cardFailed ? 'Try card again' : 'Pay by card' }}</x-button>
                        </form>

                        <form method="POST" action="{{ route('gift-vouchers.transfer.choose', $order->reference) }}" class="flex flex-col gap-3 rounded-sm border border-line-strong p-6">
                            @csrf
                            <h3 class="font-semibold">Pay by bank transfer</h3>
                            <p class="text-sm text-sand-dim">Transfer using your reference <strong class="text-sand">{{ $order->reference }}</strong>, then upload the screenshot. The voucher is issued once we've checked it.</p>
                            <x-button variant="ghost" type="submit">Pay by bank transfer</x-button>
                        </form>
                    </div>

                    <p class="text-sm text-sand-dim">Payments go to {{ \App\Actions\Payments\CreateStripeCheckout::BRAND_LINE }}.</p>
                </section>
            @elseif ($status->isUnpaid())
                <section aria-labelledby="pay-title" class="flex flex-col gap-3">
                    <h2 id="pay-title" class="text-display-xs">Payment</h2>
                    <p class="text-sand-dim">You're paying by bank transfer with reference <strong class="text-sand">{{ $order->reference }}</strong>.</p>
                    <div><x-button :href="route('gift-vouchers.transfer', $order->reference)">Bank details and upload</x-button></div>
                </section>
            @endif

            <p class="text-sm text-sand-dim"><a href="{{ route('account.vouchers') }}" class="underline underline-offset-4 hover:text-sand">Your gift vouchers</a></p>
        </div>
    </section>
</x-layouts.app>
