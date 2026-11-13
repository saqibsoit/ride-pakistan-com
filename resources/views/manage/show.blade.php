{{--
    /manage/{reference}: one order without signing in (guest checkout).
    Shows only what belongs to this order: never the account's name, email,
    other bookings, riders, credit or orders.
--}}
@php
    $tz = config('app.display_timezone');
    $upcoming = $bookings->filter(fn ($b) => $b->status === \App\Enums\BookingStatus::Confirmed && $b->slot->starts_at->isFuture());
    $others = $bookings->reject(fn ($b) => $upcoming->contains($b));
@endphp

<x-layouts.app :title="'Booking '.$order->reference" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="manage-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Manage your booking</p>
        <h1 id="manage-title" class="mt-2 text-display-lg">{{ $order->productName() }}</h1>
        <p class="mt-2 text-sand-dim">Order <strong class="text-sand">{{ $order->reference }}</strong> · <x-badge :variant="$unpaid ? 'warning' : 'neutral'">{{ $order->status->label() }}</x-badge></p>

        @if (session('status'))
            <x-alert variant="success" class="mt-8" title="Done.">{{ session('status') }}</x-alert>
        @endif

        @if ($errors->has('booking'))
            <x-alert variant="error" class="mt-8" title="That didn’t work.">{{ $errors->first('booking') }}</x-alert>
        @endif

        @if ($unpaid)
            <div class="mt-8 flex flex-col gap-3 rounded-sm border border-sand p-5">
                @if ($order->status === \App\Enums\OrderStatus::AwaitingVerification)
                    <h2 class="font-semibold">Your bank transfer is being checked</h2>
                    <p class="text-sm text-sand-dim">We’ll email you as soon as it’s confirmed. Your seats stay held until then.</p>
                    <div><x-button :href="route('checkout.transfer', $order->reference)" variant="ghost">See the transfer</x-button></div>
                @elseif ($transfer)
                    <h2 class="font-semibold">Waiting for your bank transfer</h2>
                    <p class="text-sm text-sand-dim">Upload the screenshot of your transfer before the deadline to keep your seats.</p>
                    <div><x-button :href="route('checkout.transfer', $order->reference)">Upload your transfer</x-button></div>
                @else
                    <h2 class="font-semibold">Not paid yet</h2>
                    <p class="text-sm text-sand-dim">Pay by card or bank transfer to confirm it.</p>
                    <div><x-button :href="route('checkout.show', $order->reference)">Pay now</x-button></div>
                @endif
            </div>
        @endif

        @if ($upcoming->isNotEmpty())
            <h2 class="mt-12 text-display-xs font-extrabold">Your tickets</h2>
            <p class="mt-1 text-sm text-sand-dim">Show the code at the gate. Staff scan it to check you in.</p>
            <ul class="mt-6 flex flex-col gap-6">
                @foreach ($upcoming as $booking)
                    <li class="flex flex-col gap-4 rounded-sm border border-line-strong bg-earth-2 p-5 sm:flex-row sm:items-center">
                        <div class="w-36 shrink-0 rounded-sm bg-sand p-2 text-earth" role="img" aria-label="QR code for ticket {{ $booking->ticket_code }}">
                            {!! \App\Support\TicketQr::svg($booking) !!}
                        </div>
                        <div class="flex flex-col gap-1">
                            <p class="font-semibold">{{ $booking->rider->name }}</p>
                            <p><time datetime="{{ $booking->slot->starts_at->toIso8601String() }}">{{ $booking->slot->label() }}</time> <span class="text-sand-dim">(Pakistan time)</span></p>
                            <p class="text-sm text-sand-dim">Ticket <strong class="tracking-label text-sand">{{ $booking->ticket_code }}</strong></p>
                            <p class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                <a href="{{ $google[$booking->id] }}" class="underline underline-offset-4" rel="noopener" target="_blank">Add to Google Calendar</a>
                                <a href="{{ route('manage.calendar', [$order->reference, $booking->ticket_code]) }}" class="underline underline-offset-4">Apple or Outlook (.ics)</a>
                            </p>
                            @if ($changeable[$booking->id] ?? false)
                                <p class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                    <a href="{{ route('manage.reschedule', [$order->reference, $booking->ticket_code]) }}" class="underline underline-offset-4">Move</a>
                                    <a href="{{ route('manage.cancel', [$order->reference, $booking->ticket_code]) }}" class="underline underline-offset-4">Cancel</a>
                                </p>
                            @elseif (! $booking->usesCredit())
                                <p class="text-sm text-sand-dim">To change this session, <a href="{{ route('contact') }}" class="underline underline-offset-4">contact us</a>.</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-sm text-sand-dim">Sessions booked with package credits can be moved or cancelled up to {{ $cutoffHours }} hours before they start.</p>
        @endif

        @if ($pack)
            <div class="mt-12 flex flex-col gap-3 rounded-sm border border-line-strong p-5">
                <h2 class="font-semibold">Your package</h2>
                <p>{{ $pack->creditsRemaining() }} {{ $pack->creditsRemaining() === 1 ? 'credit' : 'credits' }} left, valid until {{ $pack->expires_at->setTimezone($tz)->format('j M Y') }}.</p>
                @if ($pack->isUsable() && $pack->creditsRemaining() > 0)
                    <div><x-button :href="route('manage.book', $order->reference)">Book a session with your credits</x-button></div>
                @endif
            </div>
        @endif

        @if ($others->isNotEmpty())
            <h2 class="mt-12 text-sm font-semibold">Past and cancelled</h2>
            <ul class="mt-2 flex flex-col gap-1 text-sm text-sand-dim">
                @foreach ($others as $booking)
                    <li>{{ $booking->rider->name }}: {{ $booking->slot->label() }} · {{ $booking->status->label() }}</li>
                @endforeach
            </ul>
        @endif

        <div class="mt-12 flex flex-col gap-3 border-t border-line pt-8 text-sm text-sand-dim">
            <p>This page works until <time datetime="{{ $endsAt->toIso8601String() }}">{{ $endsAt->setTimezone($tz)->format('j M Y') }}</time>, 7 days after your last session. Anyone with your link can open it, so please don’t share it.</p>
            <form method="POST" action="{{ route('manage.revoke', $order->reference) }}" class="flex flex-col gap-2">
                @csrf
                <p>Shared the link by mistake? Stop every link sent so far and we’ll email you a new one.</p>
                <div><x-button type="submit" variant="ghost">Stop these links</x-button></div>
            </form>
            <p>To see all your bookings in one place, <a href="{{ route('login') }}" class="underline underline-offset-4">sign in with your email</a>.</p>
        </div>
    </section>
</x-layouts.app>
