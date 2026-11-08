@php($tz = config('app.display_timezone'))

<x-layouts.account title="Orders">
    @if ($orders->isEmpty())
        <p class="text-sand-dim">No orders yet.</p>
    @else
        <ul class="flex flex-col gap-3">
            @foreach ($orders as $order)
                <li class="flex flex-wrap items-center justify-between gap-4 rounded-sm border border-line bg-earth-2 p-5">
                    <div class="flex flex-col gap-1">
                        <p class="font-semibold text-sand">{{ $order->product->name }}</p>
                        <p class="text-sm text-sand-dim">
                            {{ $order->reference }} ·
                            <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->setTimezone($tz)->format('j M Y') }}</time> ·
                            <x-money :minor="$order->amount_minor" :currency="$order->currency" />
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-account.order-status :status="$order->status" />
                        <x-button :href="route('account.orders.show', $order->reference)" variant="ghost" size="sm">
                            {{ $order->status === \App\Enums\OrderStatus::Paid || $order->amount_refunded_minor > 0 ? 'Receipt' : 'Details' }}<span class="sr-only"> for {{ $order->reference }}</span>
                        </x-button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.account>
