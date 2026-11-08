{{-- Voucher history modal (milestone 10e): balance changes, newest first. --}}
@php($tz = config('app.display_timezone'))
<div class="flex flex-col gap-4 text-sm">
    <dl class="grid grid-cols-2 gap-2">
        <dt class="font-medium">Value / left</dt>
        <dd>{{ \App\Support\Money::format($voucher->initial_minor, $voucher->currency) }} / {{ \App\Support\Money::format($voucher->balance_minor, $voucher->currency) }}</dd>
        <dt class="font-medium">Source</dt>
        <dd>{{ $voucher->voucherOrder ? 'Purchase '.$voucher->voucherOrder->reference : 'Issued by an admin: '.$voucher->issue_reason }}</dd>
        @if ($voucher->void_reason)
            <dt class="font-medium">Void reason</dt>
            <dd>{{ $voucher->void_reason }}</dd>
        @endif
    </dl>

    <table class="w-full text-left">
        <caption class="sr-only">Balance history</caption>
        <thead>
            <tr>
                <th scope="col" class="py-1 pr-3 font-medium">When (Pakistan time)</th>
                <th scope="col" class="py-1 pr-3 font-medium">Change</th>
                <th scope="col" class="py-1 pr-3 font-medium">Reason</th>
                <th scope="col" class="py-1 font-medium">By</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($voucher->ledger as $entry)
                <tr class="border-t">
                    <td class="py-1 pr-3">{{ $entry->created_at->setTimezone($tz)->format('j M Y H:i') }}</td>
                    <td class="py-1 pr-3">{{ ($entry->delta_minor > 0 ? '+' : '').\App\Support\Money::format($entry->delta_minor, $voucher->currency) }}</td>
                    <td class="py-1 pr-3">{{ $entry->reason->label() }}@if ($entry->note) · {{ $entry->note }}@endif</td>
                    <td class="py-1">{{ $entry->actor?->name ?? 'System' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
