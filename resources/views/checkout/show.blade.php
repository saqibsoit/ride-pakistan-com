{{-- /checkout/{reference}: order summary and seat hold. Payment methods arrive with Stripe and bank transfer. --}}
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

            @if ($isUnpaid)
                <section aria-labelledby="pay-title" class="flex flex-col gap-3">
                    <h2 id="pay-title" class="text-display-xs">Payment</h2>
                    <p class="text-sand-dim">Card and bank transfer payment will be available on this page shortly. Use your order reference <strong class="text-sand">{{ $order->reference }}</strong> if you contact us.</p>
                </section>
            @endif

            <p class="text-sm text-sand-dim"><a href="{{ route('account.orders.show', $order->reference) }}" class="underline underline-offset-4 hover:text-sand">See this order in your account</a></p>
        </div>
    </section>
</x-layouts.app>
