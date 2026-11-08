@php
    use App\Enums\VoucherOrderStatus;
    $tz = config('app.display_timezone');
@endphp

<x-layouts.account title="Gift vouchers" intro="Gift vouchers you've bought. Each one is emailed to its recipient once the payment is confirmed.">
    <div><x-button :href="route('gift-vouchers.create')">Buy a gift voucher</x-button></div>

    @if ($orders->isEmpty())
        <p class="text-sand-dim">No gift vouchers yet.</p>
    @else
        <ul class="flex flex-col gap-4">
            @foreach ($orders as $order)
                @php($voucher = $order->voucher)
                <li class="flex flex-col gap-4 rounded-sm border border-line bg-earth-2 p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex flex-col gap-1">
                            <h2 class="text-display-xs">{{ $order->label() }}, <x-money :minor="$order->amount_minor" :currency="$order->currency" /></h2>
                            <p class="text-sm text-sand-dim break-all">For {{ $order->recipient_name }} ({{ $order->recipient_email }}) · {{ $order->reference }}</p>
                        </div>
                        <x-badge :variant="$order->status === VoucherOrderStatus::Paid ? 'success' : ($order->status->isUnpaid() ? 'warning' : 'neutral')">{{ $order->status->label() }}</x-badge>
                    </div>

                    @if ($voucher)
                        <dl class="grid gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-sand-dim">Code</dt>
                                <dd class="font-semibold">{{ $voucher->formattedCode() }}</dd>
                            </div>
                            <div>
                                <dt class="text-sand-dim">Left</dt>
                                <dd class="font-semibold"><x-money :minor="$voucher->balance_minor" :currency="$voucher->currency" /> · {{ $voucher->status->label() }}</dd>
                            </div>
                            <div>
                                <dt class="text-sand-dim">{{ $voucher->delivered_at ? 'Sent' : 'Sending' }}</dt>
                                <dd class="font-semibold">
                                    @if ($voucher->delivered_at)
                                        <time datetime="{{ $voucher->delivered_at->toIso8601String() }}">{{ $voucher->delivered_at->setTimezone($tz)->format('j F Y') }}</time>
                                    @else
                                        {{ $order->send_on ? $order->send_on->format('j F Y').', 9 am' : 'Shortly' }}
                                    @endif
                                </dd>
                            </div>
                        </dl>
                        <p class="text-sm text-sand-dim">Valid until <time datetime="{{ $voucher->expires_at->toIso8601String() }}">{{ $voucher->expires_at->setTimezone($tz)->format('j F Y') }}</time>.</p>
                    @elseif ($order->status->isUnpaid())
                        <div><x-button size="sm" variant="ghost" :href="route('gift-vouchers.show', $order->reference)">Finish paying</x-button></div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.account>
