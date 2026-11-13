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
            @php($payBy = \App\Actions\Payments\TransferWindow::payBy($order))
            @if ($order->status === \App\Enums\OrderStatus::PendingPayment && $payBy)
                {{-- Approved private session request (prompt 15B). --}}
                <x-alert variant="info" title="Pay by {{ $payBy->setTimezone($tz)->format('D j M, g:i a') }} (Pakistan time).">
                    Your private session is approved and held for you until then. Pay by card, or choose bank transfer and upload the screenshot before then. After that the slot is released.
                </x-alert>
            @elseif ($order->status === \App\Enums\OrderStatus::PendingPayment && $heldUntil && $slotItems->isNotEmpty())
                <x-alert variant="info" title="Your {{ $order->isPrivate() ? 'slot is' : 'seats are' }} held.">
                    We keep them for you until <time datetime="{{ $heldUntil->toIso8601String() }}">{{ $heldUntil->setTimezone($tz)->format('g:i a') }}</time> (Pakistan time). After that they go back on sale.
                </x-alert>
            @elseif ($order->status === \App\Enums\OrderStatus::Expired)
                <x-alert variant="warning" title="This order has expired.">The seat hold ran out before payment. <a href="{{ route('book') }}" class="underline underline-offset-4">Book again</a>.</x-alert>
            @endif

            @if ($guest && session('guest_email'))
                {{-- Guest checkout: the address they typed, never the account's details. --}}
                <p class="text-sm text-sand-dim">Booking as a guest. Your tickets, receipt and a link to manage this booking go to <span class="font-semibold text-sand">{{ session('guest_email') }}</span>.</p>
            @endif

            <dl class="grid gap-4 rounded-sm border border-line-strong bg-earth-2 p-6 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-sand-dim">Session</dt>
                    <dd class="font-semibold">{{ $order->productName() }}</dd>
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
                @if ($order->discounts->isNotEmpty())
                    {{-- Discounts, worked out on the server (milestone 10e Part B). --}}
                    <div class="border-t border-line pt-4 sm:col-span-2">
                        <dt class="sr-only">Price and discounts</dt>
                        <dd>
                            <ul class="flex flex-col gap-2" role="list">
                                <li class="flex justify-between gap-4"><span class="text-sand-dim">Subtotal</span><x-money :minor="$order->subtotal_minor" :currency="$order->currency" /></li>
                                @foreach ($order->discounts as $line)
                                    <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                        <span class="text-sand-dim">{{ $line->label }}</span>
                                        <span class="flex items-center gap-3">
                                            <span>−&nbsp;<x-money :minor="$line->amount_minor" :currency="$order->currency" /></span>
                                            @if ($order->status === \App\Enums\OrderStatus::PendingPayment && $line->kind !== \App\Enums\DiscountKind::Referral)
                                                <form method="POST" action="{{ route('checkout.discount.remove', [$order->reference, $line->kind->value]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-sm underline underline-offset-4 hover:text-sand">Remove<span class="sr-only"> {{ $line->kind->label() }}</span></button>
                                                </form>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </dd>
                    </div>
                @endif
                <div class="border-t border-line pt-4 sm:col-span-2">
                    <dt class="text-sm text-sand-dim">{{ $order->discounts->isNotEmpty() ? 'To pay' : 'Total' }}</dt>
                    <dd class="font-display text-display-md font-black"><x-money :minor="$order->amount_minor" :currency="$order->currency" /></dd>
                </div>
            </dl>

            @if (session('discount_status'))
                <x-alert variant="success">{{ session('discount_status') }}</x-alert>
            @endif

            @if (session('checkout_error'))
                <x-alert variant="error" title="That didn't work.">{{ session('checkout_error') }}</x-alert>
            @endif

            @if ($order->status === \App\Enums\OrderStatus::PendingPayment)
                <section aria-labelledby="discount-title" class="flex flex-col gap-4 rounded-sm border border-line p-6">
                    <h2 id="discount-title" class="text-display-xs">Gift voucher or promo code</h2>
                    <form method="POST" action="{{ route('checkout.discount', $order->reference) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        @csrf
                        <div class="grow">
                            <x-form.field name="code" label="Code" required autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="60" hint="One promo code per order; a gift voucher can be added as well." />
                        </div>
                        <x-button type="submit" variant="ghost">Apply</x-button>
                    </form>

                    @if ($creditAvailable > 0)
                        <form method="POST" action="{{ route('checkout.credit', $order->reference) }}" class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                            @csrf
                            <input type="hidden" name="use" value="{{ $choices['use_credit'] ? '0' : '1' }}">
                            <p class="text-sm">You have <strong><x-money :minor="$creditAvailable" /></strong> of account credit{{ $choices['use_credit'] ? ' (in use on this order)' : '' }}.</p>
                            <x-button type="submit" variant="ghost" size="sm">{{ $choices['use_credit'] ? 'Don’t use my credit' : 'Use my credit' }}</x-button>
                        </form>
                    @endif
                </section>
            @endif

            @if ($order->status === \App\Enums\OrderStatus::PendingPayment && $order->amount_minor === 0 && $order->discounts->isNotEmpty())
                <section aria-labelledby="pay-title" class="flex flex-col gap-4">
                    <h2 id="pay-title" class="text-display-xs">Nothing left to pay.</h2>
                    <p class="text-sand-dim">Your discounts cover the whole order. Confirm to get your {{ $slotItems->isNotEmpty() ? 'tickets' : 'package' }} by email.</p>
                    <form method="POST" action="{{ route('checkout.confirm', $order->reference) }}">
                        @csrf
                        <x-button type="submit">Confirm booking</x-button>
                    </form>
                    <x-payment-terms verb="confirming" />
                </section>
            @elseif ($order->status === \App\Enums\OrderStatus::PendingPayment)
                <section aria-labelledby="pay-title" class="flex flex-col gap-5">
                    <h2 id="pay-title" class="text-display-xs">How would you like to pay?</h2>

                    @if ($cardFailed)
                        <x-alert variant="warning" title="Your card payment didn't go through.">Your {{ $order->isPrivate() ? 'slot is' : 'seats are' }} still held. Try a card again, or pay by bank transfer with the same reference.</x-alert>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        @if ($cardOffered)
                            <form method="POST" action="{{ route('checkout.card', $order->reference) }}" class="flex flex-col gap-3 rounded-sm border border-line-strong p-6">
                                @csrf
                                <h3 class="font-semibold">Pay by card</h3>
                                <p class="text-sm text-sand-dim">Secure payment on Stripe's checkout page, in Pakistani rupees. Your booking is confirmed as soon as the payment clears.</p>
                                <x-button type="submit">{{ $cardFailed ? 'Try card again' : 'Pay by card' }}</x-button>
                            </form>
                        @else
                            <div class="flex flex-col gap-3 rounded-sm border border-line p-6">
                                <h3 class="font-semibold">Pay by card</h3>
                                <p class="text-sm text-sand-dim">The amount left is below the smallest card payment. Please pay it by bank transfer.</p>
                            </div>
                        @endif

                        <div class="flex flex-col gap-3 rounded-sm border border-line-strong p-6">
                            <h3 class="font-semibold">Pay by bank transfer</h3>
                            <p class="text-sm text-sand-dim">Transfer to our bank account using your reference <strong class="text-sand">{{ $order->reference }}</strong>, then upload the screenshot. We confirm once we've checked it.</p>
                            @if ($transferOffered)
                                <form method="POST" action="{{ route('checkout.transfer.choose', $order->reference) }}">
                                    @csrf
                                    <x-button variant="ghost" type="submit">Pay by bank transfer</x-button>
                                </form>
                            @else
                                <p class="text-sm text-sand-dim">Your session starts too soon for a bank transfer to be checked in time. Please pay by card.</p>
                            @endif
                        </div>
                    </div>

                    <x-payment-terms />

                    <p class="text-sm text-sand-dim">Some Pakistani bank cards block international online payments. If your card is declined, ask your bank to allow it, or pay by bank transfer instead.</p>
                    <p class="text-sm text-sand-dim">Payments go to {{ \App\Actions\Payments\CreateStripeCheckout::BRAND_LINE }}.</p>
                </section>
            @elseif ($isUnpaid)
                <section aria-labelledby="pay-title" class="flex flex-col gap-3">
                    <h2 id="pay-title" class="text-display-xs">Payment</h2>
                    <p class="text-sand-dim">You're paying by bank transfer with reference <strong class="text-sand">{{ $order->reference }}</strong>.</p>
                    <div><x-button :href="route('checkout.transfer', $order->reference)">Bank details and upload</x-button></div>
                </section>
            @endif

            <p class="text-sm text-sand-dim"><a href="{{ route('account.orders.show', $order->reference) }}" class="underline underline-offset-4 hover:text-sand">See this order in your account</a></p>
        </div>
    </section>
</x-layouts.app>
