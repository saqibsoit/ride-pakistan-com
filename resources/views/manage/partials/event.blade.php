{{-- An event booking on the manage page (decided 2026-11-22): payment, balance, cancel, or our cancelled date. --}}
@php
    use App\Actions\Events\RespondToCancelledEventDate;
    use App\Support\Money;
    $pending = RespondToCancelledEventDate::pending($order);
    $alternatives = $pending ? RespondToCancelledEventDate::alternatives($order) : collect();
    $canCancel = app(\App\Actions\Events\CancelEventBooking::class)->canCancel($order, $order->user);
@endphp

@if ($pending)
    <section class="mt-8 flex flex-col gap-4 rounded-sm border border-sand p-5" aria-labelledby="date-cancelled-title">
        <h2 id="date-cancelled-title" class="font-semibold">We’ve had to cancel this date</h2>
        <p class="text-sm text-sand-dim">Sorry about that. Choose a full refund of everything you paid (deposit included), or move to another date.</p>
        @if ($alternatives->isNotEmpty())
            <form method="POST" action="{{ route('manage.event.move', $order->reference) }}" class="flex flex-col gap-3">
                @csrf
                <x-form.field name="slot" label="Another date" type="select" required>
                    @foreach ($alternatives as $slot)
                        <option value="{{ $slot->id }}">{{ $slot->dateRangeLabel() }}</option>
                    @endforeach
                </x-form.field>
                <div><x-button type="submit">Move to this date</x-button></div>
            </form>
        @endif
        <form method="POST" action="{{ route('manage.event.refund', $order->reference) }}">
            @csrf
            <x-button type="submit" :variant="$alternatives->isNotEmpty() ? 'ghost' : 'primary'">Refund everything</x-button>
        </form>
    </section>
@endif

@if ($event->order->deposit_percent !== null || $event->balanceStatus() !== null)
    <section class="mt-8 flex flex-col gap-3 rounded-sm border border-line-strong bg-earth-2 p-5" aria-labelledby="payment-title">
        <h2 id="payment-title" class="font-semibold">Payment</h2>
        <dl class="flex flex-col gap-1 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-sand-dim">Price</dt><dd>{{ Money::format($order->priceMinor(), $order->currency) }}</dd></div>
            @if ($order->deposit_percent !== null)
                <div class="flex justify-between gap-4"><dt class="text-sand-dim">Deposit paid ({{ $order->deposit_percent }}%, non-refundable)</dt><dd>{{ Money::format($order->amount_minor, $order->currency) }}</dd></div>
            @endif
            @if ($event->balanceLine() !== '')
                <div class="flex justify-between gap-4"><dt class="text-sand-dim">{{ $event->hasBalanceDue() ? 'Balance due' : 'Balance' }}</dt><dd class="font-semibold">{{ $event->balanceLine() }}</dd></div>
            @endif
        </dl>
        @if ($event->canPayBalanceOnline())
            <form method="POST" action="{{ route('manage.event.balance', $order->reference) }}">
                @csrf
                <x-button type="submit">Pay the balance by card ({{ Money::format($event->balanceMinor(), $order->currency) }})</x-button>
            </form>
        @endif
        @if (request('balance') === 'paid' && $event->hasBalanceDue())
            <p class="text-sm text-sand-dim" role="status">Thanks: we’re confirming your payment. It can take a minute to show here.</p>
        @endif
    </section>
@endif

@if ($event->dressCode() !== [] || $event->whatToBring() !== [])
    <section class="mt-8 flex flex-col gap-3" aria-labelledby="prepare-title">
        <h2 id="prepare-title" class="font-semibold">Before you come</h2>
        <ul class="tick-list text-sm">
            @foreach ([...$event->dressCode(), ...$event->whatToBring()] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </section>
@endif

<section class="mt-8 flex flex-col gap-3" aria-labelledby="rules-title">
    <h2 id="rules-title" class="font-semibold">Cancellations</h2>
    <ul class="flex list-disc flex-col gap-1 ps-5 text-sm text-sand-dim">
        @foreach ($event->terms() as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
    @if ($canCancel && ! $pending)
        <div><x-button :href="route('manage.event.cancel', $order->reference)" variant="ghost" size="sm">Cancel this booking</x-button></div>
    @endif
</section>
