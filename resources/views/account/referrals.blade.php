@php
    use App\Enums\ReferralStatus;
    use App\Support\Money;
    $tz = config('app.display_timezone');
@endphp

<x-layouts.account title="Refer a friend" intro="Share your link with friends who haven't ridden with us yet.">
    <section aria-labelledby="link-title" class="flex flex-col gap-4 rounded-sm border border-line-strong bg-earth-2 p-6">
        <h2 id="link-title" class="text-display-xs">Your link</h2>
        {{-- The friend's discount ($friendDiscountMinor) is applied at checkout from milestone 10e part B; it is mentioned here once it is. --}}
        @if ($rewardMinor > 0)
            <p class="text-sand-dim">Once a friend who signed up with it has paid for and attended a session, you get {{ Money::format($rewardMinor) }} of account credit.</p>
        @endif
        <div class="flex flex-wrap items-center gap-3" x-data="copyButton" data-value="{{ $link }}" data-label="Referral link">
            <span class="font-semibold break-all select-all">{{ $link }}</span>
            <button type="button" x-cloak x-on:click="copy" class="min-h-11 rounded-sm border border-line-strong px-3 text-sm hover:border-sand" aria-label="Copy your referral link">
                <span x-text="label">Copy</span>
            </button>
            <span class="sr-only" aria-live="polite" x-text="announcement"></span>
        </div>
        <p class="text-sm text-sand-dim">It only works for new accounts, and not for your own other email addresses or phone number.</p>
    </section>

    <section aria-labelledby="credit-title" class="flex flex-col gap-4">
        <h2 id="credit-title" class="text-display-xs">Account credit: <x-money :minor="$balanceMinor" /></h2>
        @if ($entries->isEmpty())
            <p class="text-sand-dim">No credit yet.</p>
        @else
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Account credit history</caption>
                <thead class="text-sand-dim">
                    <tr>
                        <th scope="col" class="py-2 pr-4 font-semibold">Date</th>
                        <th scope="col" class="py-2 pr-4 font-semibold">Change</th>
                        <th scope="col" class="py-2 font-semibold">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr class="border-t border-line">
                            <td class="py-2 pr-4"><time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->setTimezone($tz)->format('j M Y') }}</time></td>
                            <td class="py-2 pr-4">{{ ($entry->delta_minor > 0 ? '+' : '').Money::format($entry->delta_minor) }}</td>
                            <td class="py-2">{{ $entry->reason->label() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section aria-labelledby="friends-title" class="flex flex-col gap-4">
        <h2 id="friends-title" class="text-display-xs">Friends who signed up</h2>
        @if ($referrals->isEmpty())
            <p class="text-sand-dim">Nobody yet.</p>
        @else
            <ul class="flex flex-col divide-y divide-line border-y border-line">
                @foreach ($referrals as $referral)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <span>{{ $referral->referred->name ? \Illuminate\Support\Str::of($referral->referred->name)->explode(' ')->first() : 'A friend' }} · joined {{ $referral->created_at->setTimezone($tz)->format('j M Y') }}</span>
                        <x-badge :variant="match ($referral->status) { ReferralStatus::Rewarded => 'success', ReferralStatus::Rejected => 'neutral', default => 'warning' }">
                            {{ match ($referral->status) { ReferralStatus::Rewarded => 'Credit added', ReferralStatus::Rejected => 'Not eligible', default => 'Waiting for their first session' } }}
                        </x-badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.account>
