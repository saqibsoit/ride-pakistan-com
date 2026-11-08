{{-- /account/orders/{reference}: order status and printable receipt. --}}
@php
    $tz = config('app.display_timezone');
    $settings = app(\App\Support\Settings::class);
    $isReceipt = $order->paid_at !== null;
@endphp

<x-layouts.account :title="($isReceipt ? 'Receipt ' : 'Order ').$order->reference">
    <article aria-label="{{ $isReceipt ? 'Receipt' : 'Order' }} {{ $order->reference }}" class="flex flex-col gap-8 rounded-sm border border-line-strong bg-earth-2 p-6 md:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-1 text-sm text-sand-dim">
                <p class="font-semibold text-sand">{{ $settings->string(\App\Enums\SettingKey::BusinessName) }}</p>
                <p>A {{ $settings->string(\App\Enums\SettingKey::ParentCompany) }} company</p>
                <p>{{ $settings->string(\App\Enums\SettingKey::Email) }}</p>
            </div>
            <x-account.order-status :status="$order->status" />
        </div>

        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-sand-dim">Reference</dt>
                <dd class="font-semibold text-sand">{{ $order->reference }}</dd>
            </div>
            <div>
                <dt class="text-sm text-sand-dim">Customer</dt>
                <dd class="text-sand">{{ $user->displayName() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-sand-dim">Ordered</dt>
                <dd class="text-sand"><time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->setTimezone($tz)->format('j F Y, H:i') }}</time></dd>
            </div>
            @if ($order->paid_at)
                <div>
                    <dt class="text-sm text-sand-dim">Paid</dt>
                    <dd class="text-sand"><time datetime="{{ $order->paid_at->toIso8601String() }}">{{ $order->paid_at->setTimezone($tz)->format('j F Y, H:i') }}</time></dd>
                </div>
            @endif
            @if ($order->payment_method)
                <div>
                    <dt class="text-sm text-sand-dim">Payment method</dt>
                    <dd class="text-sand">{{ $order->payment_method->label() }}</dd>
                </div>
            @endif
        </dl>

        <table class="w-full text-left text-sm">
            <caption class="sr-only">Items in order {{ $order->reference }}</caption>
            <thead class="text-sand-dim">
                <tr>
                    <th scope="col" class="py-2 pr-4 font-semibold">Item</th>
                    <th scope="col" class="py-2 pr-4 font-semibold">Rider</th>
                    <th scope="col" class="py-2 text-right font-semibold">Price</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($order->items as $item)
                    <tr class="border-t border-line">
                        <td class="py-3 pr-4">
                            {{ $order->product->name }}
                            @if ($item->slot)
                                <span class="block text-sand-dim">{{ $item->slot->label() }}</span>
                            @endif
                        </td>
                        <td class="py-3 pr-4">{{ $item->rider->name ?? '—' }}</td>
                        <td class="py-3 text-right"><x-money :minor="$item->unit_price_minor" :currency="$order->currency" /></td>
                    </tr>
                @empty
                    <tr class="border-t border-line">
                        <td class="py-3 pr-4" colspan="2">{{ $order->product->name }}</td>
                        <td class="py-3 text-right"><x-money :minor="$order->amount_minor" :currency="$order->currency" /></td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t border-line-strong">
                    <th scope="row" colspan="2" class="py-3 pr-4 font-semibold text-sand">Total</th>
                    <td class="py-3 text-right font-semibold text-sand"><x-money :minor="$order->amount_minor" :currency="$order->currency" /></td>
                </tr>
                @if ($order->amount_refunded_minor > 0)
                    <tr>
                        <th scope="row" colspan="2" class="py-1 pr-4 font-normal text-sand-dim">Refunded</th>
                        <td class="py-1 text-right text-sand-dim">−<x-money :minor="$order->amount_refunded_minor" :currency="$order->currency" /></td>
                    </tr>
                @endif
            </tfoot>
        </table>
    </article>

    <p class="text-sm text-sand-dim print:hidden">Use your browser’s print option to save or print this {{ $isReceipt ? 'receipt' : 'page' }}.</p>
</x-layouts.account>
