{{-- /checkout/{reference}: order summary, seat hold and payment options (card now; bank transfer in milestone 7). --}}
@php
    $tz = config('app.display_timezone');
    $isUnpaid = $order->status->isUnpaid();
    $heldUntil = $order->holds->max('expires_at') ?? $order->expires_at;
    $slotItems = $order->items->whereNotNull('slot_id');
@endphp

<x-layouts.app title="Checkout {{ $order->reference }}" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="checkout-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Order {{ $order->reference }}</p>
        <h1 id="checkout-title" class="mt-3 text-display-xl">{{ $isUnpaid ? 'Almost there.' : 'Your order.' }}</h1>

        <div class="mt-10 flex flex-col gap-6">
            @if ($order->status === \App\Enums\OrderStatus::PendingPayment && $heldUntil && $slotItems->isNotEmpty())
                <x-alert variant="info" title="Your {{ $order->product->type === \App\Enums\ProductType::Private ? 'slot is' : 'seats are' }} held.">
                    We keep them for you until <time datetime="{{ $heldUntil->toIso8601String() }}">{{ $heldUntil->setTimezone($tz)->format('g:i a') }}</time> (Pakistan time). After that they go back on sale.
                </x-alert>
            @elseif ($order->status === \App\Enums\OrderStatus::Expired)
                <x-alert variant="warning" title="This order has expired.">The seat hold ran out before payment. <a href="{{ route('book') }}" class="underline underline-offset-4">Book again</a>.</x-alert>
            @endif

            <dl class="grid gap-4 rounded-sm border border-line-strong bg-earth-2 p-6 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-sand-dim">Session</dt>
                    <dd class="font-semibold">{{ $order->product->name }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">Status</dt>
                    <dd><x-account.order-status :status="$order->status" /></dd>
                </div>
                @if ($slotItems->isNotEmpty())
                    <div>
                        <dt class="text-sm text-sand-dim">When (Pakistan time)</dt>
                        <dd class="font-semibold">{{ $slotItems->first()->slot?->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-sand-dim">Riders</dt>
                        <dd class="font-semibold">{{ $slotItems->map(fn ($i) => $i->rider?->name)->filter()->join(', ') }}</dd>
                    </div>
                @endif
                <div class="border-t border-line pt-4 sm:col-span-2">
                    <dt class="text-sm text-sand-dim">Total</dt>
                    <dd class="font-display text-display-md font-black"><x-money :minor="$order->amount_minor" :currency="$order->currency" /></dd>
                </div>
            </dl>

            @if (session('checkout_error'))
                <x-alert variant="error" title="Card payment didn't start.">{{ session('checkout_error') }}</x-alert>
            @endif

            @if ($order->status === \App\Enums\OrderStatus::PendingPayment)
                <section aria-labelledby="pay-title" class="flex flex-col gap-5">
                    <h2 id="pay-title" class="text-display-xs">How would you like to pay?</h2>

                    @if ($cardFailed)
                        <x-alert variant="warning" title="Your card payment didn't go through.">Your {{ $order->product->type === \App\Enums\ProductType::Private ? 'slot is' : 'seats are' }} still held. Try a card again, or pay by bank transfer with the same reference.</x-alert>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <form method="POST" action="{{ route('checkout.card', $order->reference) }}" class="flex flex-col gap-3 rounded-sm border border-line-strong p-6">
                            @csrf
                            <h3 class="font-semibold">Pay by card</h3>
                            <p class="text-sm text-sand-dim">Secure payment on Stripe's checkout page, in Pakistani rupees. Your booking is confirmed as soon as the payment clears.</p>
                            <x-button type="submit">{{ $cardFailed ? 'Try card again' : 'Pay by card' }}</x-button>
                        </form>

                        <div class="flex flex-col gap-3 rounded-sm border border-line-strong p-6">
                            <h3 class="font-semibold">Pay by bank transfer</h3>
                            <p class="text-sm text-sand-dim">Transfer to our bank account using your reference <strong class="text-sand">{{ $order->reference }}</strong>, then upload the screenshot. We confirm once we've checked it.</p>
                            <p class="text-sm text-sand-dim" id="transfer-soon">Bank transfer checkout opens here very soon. Until then, message us with your reference.</p>
                            <x-button variant="ghost" disabled aria-disabled="true" aria-describedby="transfer-soon">Pay by bank transfer</x-button>
                        </div>
                    </div>

                    <p class="text-sm text-sand-dim">Some Pakistani bank cards block international online payments. If your card is declined, ask your bank to allow it, or pay by bank transfer instead.</p>
                    <p class="text-sm text-sand-dim">Payments go to {{ \App\Actions\Payments\CreateStripeCheckout::BRAND_LINE }}.</p>
                </section>
            @elseif ($isUnpaid)
                <section aria-labelledby="pay-title" class="flex flex-col gap-3">
                    <h2 id="pay-title" class="text-display-xs">Payment</h2>
                    <p class="text-sand-dim">We're waiting for your bank transfer. Use your order reference <strong class="text-sand">{{ $order->reference }}</strong> if you contact us.</p>
                </section>
            @endif

            <p class="text-sm text-sand-dim"><a href="{{ route('account.orders.show', $order->reference) }}" class="underline underline-offset-4 hover:text-sand">See this order in your account</a></p>
        </div>
    </section>
</x-layouts.app>
