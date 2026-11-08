{{-- Promo code usage modal (milestone 10e): the latest 100 uses. Filled at checkout from Part B. --}}
@php($tz = config('app.display_timezone'))
<div class="flex flex-col gap-4 text-sm">
    <p>{{ $promo->summary() }} · used {{ $promo->redemptions_count ?? $promo->redemptions()->count() }}{{ $promo->max_uses !== null ? ' of '.$promo->max_uses : '' }} times.</p>

    @if ($redemptions->isEmpty())
        <p>Not used yet.</p>
    @else
        <table class="w-full text-left">
            <caption class="sr-only">Uses of {{ $promo->code }}</caption>
            <thead>
                <tr>
                    <th scope="col" class="py-1 pr-3 font-medium">When (Pakistan time)</th>
                    <th scope="col" class="py-1 pr-3 font-medium">Order</th>
                    <th scope="col" class="py-1 pr-3 font-medium">Customer</th>
                    <th scope="col" class="py-1 font-medium">Discount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($redemptions as $redemption)
                    <tr class="border-t">
                        <td class="py-1 pr-3">{{ $redemption->created_at->setTimezone($tz)->format('j M Y H:i') }}</td>
                        <td class="py-1 pr-3">{{ $redemption->order->reference }}</td>
                        <td class="py-1 pr-3">{{ $redemption->user->email }}</td>
                        <td class="py-1">{{ \App\Support\Money::format($redemption->amount_minor) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
