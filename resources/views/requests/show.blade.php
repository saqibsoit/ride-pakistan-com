{{-- /requests/{reference}: one private session request without signing in (guest checkout). Only this request's details. --}}
@php($status = $item->status)
<x-layouts.app :title="'Request '.$item->reference" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="request-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Private session request {{ $item->reference }}</p>
        <h1 id="request-title" class="mt-2 text-display-lg"><time datetime="{{ $item->starts_at->toIso8601String() }}">{{ $item->label() }}</time></h1>
        <p class="mt-2 text-sand-dim">Pakistan time · {{ $item->riders }} {{ $item->riders === 1 ? 'rider' : 'riders' }}</p>

        @if (session('status'))
            <x-alert variant="success" class="mt-8" title="Done.">{{ session('status') }}</x-alert>
        @endif
        @if ($errors->has('request'))
            <x-alert variant="error" class="mt-8" title="That didn’t work.">{{ $errors->first('request') }}</x-alert>
        @endif

        <div class="mt-8 flex flex-col gap-4 rounded-sm border border-line-strong bg-earth-2 p-5">
            <p><x-badge :variant="match ($status) { \App\Enums\PrivateRequestStatus::Paid => 'success', \App\Enums\PrivateRequestStatus::Requested, \App\Enums\PrivateRequestStatus::Approved => 'warning', default => 'neutral' }">{{ $item->statusLine() }}</x-badge></p>

            @if ($payUrl)
                <div><x-button :href="$payUrl">{{ $item->order?->status === \App\Enums\OrderStatus::PendingPayment ? 'Pay now' : 'See the payment' }}</x-button></div>
            @elseif ($bookingUrl)
                <div><x-button :href="$bookingUrl">See your booking and tickets</x-button></div>
            @elseif ($status === \App\Enums\PrivateRequestStatus::Requested)
                <form method="POST" action="{{ route('requests.withdraw', $item->reference) }}">
                    @csrf
                    <x-button type="submit" variant="ghost">Withdraw this request</x-button>
                </form>
            @elseif ($status === \App\Enums\PrivateRequestStatus::Declined)
                <p class="text-sm"><span class="font-semibold">Why:</span> {{ $item->decline_reason }}</p>
                @if ($item->suggested_times)
                    <p class="text-sm"><span class="font-semibold">Times that could work:</span> {{ $item->suggested_times }}</p>
                @endif
            @endif
        </div>

        <p class="mt-8 text-sm text-sand-dim">Anyone with your link can open this page, so please don’t share it. To see all your requests and bookings, <a href="{{ route('login') }}" class="underline underline-offset-4">sign in with your email</a>.</p>
    </section>
</x-layouts.app>
