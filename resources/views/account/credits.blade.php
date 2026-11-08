@php($tz = config('app.display_timezone'))

<x-layouts.account title="Credits" intro="Package credits can be used until their expiry date. Expired credits can’t be used.">
    @if ($packs->isEmpty())
        <p class="text-sand-dim">No package credits yet. <a href="{{ route('prices') }}" class="underline underline-offset-4 hover:text-sand">See packages</a>.</p>
    @else
        <ul class="flex flex-col gap-4">
            @foreach ($packs as $pack)
                @php($usable = $pack->status === \App\Enums\CreditPackStatus::Active && $pack->expires_at->isFuture())
                <li class="flex flex-col gap-4 rounded-sm border border-line bg-earth-2 p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex flex-col gap-1">
                            <h2 class="text-display-xs">{{ $pack->order->product->name }}</h2>
                            <p class="text-sm text-sand-dim">
                                {{ $pack->creditsRemaining() }} of {{ $pack->credits_total }} {{ $pack->credits_total === 1 ? 'credit' : 'credits' }} left ·
                                {{ $usable ? 'use by' : 'expired' }}
                                <time datetime="{{ $pack->expires_at->toIso8601String() }}">{{ $pack->expires_at->setTimezone($tz)->format('j F Y') }}</time>
                            </p>
                        </div>
                        <x-badge :variant="$usable ? 'success' : 'neutral'">{{ $usable ? 'Active' : $pack->status->label() }}</x-badge>
                    </div>

                    @if ($pack->ledger->isNotEmpty())
                        <details class="text-sm">
                            <summary class="cursor-pointer text-sand-dim hover:text-sand">History</summary>
                            <table class="mt-3 w-full text-left">
                                <caption class="sr-only">Credit history for {{ $pack->order->product->name }}</caption>
                                <thead class="text-sand-dim">
                                    <tr>
                                        <th scope="col" class="py-2 pr-4 font-semibold">Date</th>
                                        <th scope="col" class="py-2 pr-4 font-semibold">Change</th>
                                        <th scope="col" class="py-2 font-semibold">Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($pack->ledger as $entry)
                                        <tr class="border-t border-line">
                                            <td class="py-2 pr-4">{{ $entry->created_at?->setTimezone($tz)->format('j M Y') }}</td>
                                            <td class="py-2 pr-4">{{ $entry->delta > 0 ? '+'.$entry->delta : $entry->delta }}</td>
                                            <td class="py-2">{{ $entry->reason->label() }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.account>
