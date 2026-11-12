<x-layouts.account title="Private requests" intro="Private sessions you’ve asked for at your own date and time. All times are Pakistan time.">
    @php($flash = [
        'private-request-sent' => 'Request sent. We’ve emailed you a copy and we’ll answer soon.',
        'private-request-withdrawn' => 'Request withdrawn.',
    ][session('status')] ?? null)
    @if ($flash)
        <x-alert variant="success">{{ $flash }}@if (session('status') === 'private-request-sent')<x-spam-hint />@endif</x-alert>
    @endif
    @if ($errors->has('request'))
        <x-alert variant="error">{{ $errors->first('request') }}</x-alert>
    @endif

    @if ($requests->isEmpty())
        <p class="text-sand-dim">You haven’t requested a private session yet.
            @if ($privateProduct)
                <a href="{{ route('book', ['product' => $privateProduct->slug]) }}" class="underline underline-offset-4 hover:text-sand">Request one</a>.
            @endif
        </p>
    @else
        <ul class="flex flex-col gap-3">
            @foreach ($requests as $item)
                @php($status = $item->status)
                <li class="flex flex-col gap-3 rounded-sm border border-line bg-earth-2 p-5" data-request="{{ $item->reference }}">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex flex-col gap-1">
                            <p class="font-semibold text-sand"><time datetime="{{ $item->starts_at->toIso8601String() }}">{{ $item->label() }}</time></p>
                            <p class="text-sm text-sand-dim">{{ $item->riders }} {{ $item->riders === 1 ? 'rider' : 'riders' }} · {{ $item->reference }}</p>
                        </div>
                        <x-badge :variant="match ($status) { \App\Enums\PrivateRequestStatus::Paid => 'success', \App\Enums\PrivateRequestStatus::Requested, \App\Enums\PrivateRequestStatus::Approved => 'warning', default => 'neutral' }">{{ $item->statusLine() }}</x-badge>
                    </div>

                    @if ($status === \App\Enums\PrivateRequestStatus::Approved && $item->order && $item->order->status === \App\Enums\OrderStatus::PendingPayment)
                        <div><x-button :href="route('checkout.show', $item->order->reference)" size="sm">Pay now<span class="sr-only"> for {{ $item->label() }}</span></x-button></div>
                    @elseif ($status === \App\Enums\PrivateRequestStatus::Approved && $item->order && $item->order->status === \App\Enums\OrderStatus::AwaitingTransfer)
                        <div><x-button :href="route('checkout.transfer', $item->order->reference)" size="sm">Upload your transfer<span class="sr-only"> for {{ $item->label() }}</span></x-button></div>
                    @elseif ($status === \App\Enums\PrivateRequestStatus::Requested)
                        <form method="POST" action="{{ route('account.private-requests.withdraw', $item->reference) }}">
                            @csrf
                            <x-button type="submit" variant="ghost" size="sm">Withdraw<span class="sr-only"> request {{ $item->reference }}</span></x-button>
                        </form>
                    @elseif ($status === \App\Enums\PrivateRequestStatus::Declined)
                        <p class="text-sm"><span class="font-semibold">Why:</span> {{ $item->decline_reason }}</p>
                        @if ($item->suggested_times)
                            <p class="text-sm"><span class="font-semibold">Times that could work:</span> {{ $item->suggested_times }}</p>
                        @endif
                    @elseif ($status === \App\Enums\PrivateRequestStatus::Paid)
                        <p class="text-sm"><a href="{{ route('account.bookings') }}" class="underline underline-offset-4 hover:text-sand">See your booking and tickets</a></p>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($privateProduct && $requests->doesntContain(fn ($r) => $r->status->isOpen()))
            <p class="mt-6"><a href="{{ route('book', ['product' => $privateProduct->slug]) }}" class="underline underline-offset-4 hover:text-sand">Request another private session</a></p>
        @endif
    @endif
</x-layouts.account>
