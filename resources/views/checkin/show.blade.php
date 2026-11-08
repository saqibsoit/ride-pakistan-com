{{-- /checkin/{code}: staff gate check-in from the ticket QR. Mobile-first, noindex, never cached. --}}
@php
    use App\Actions\Booking\CheckInBooking;
    use App\Enums\BookingStatus;

    $tz = config('app.display_timezone');
    $slot = $booking->slot;
    $product = $booking->order?->product ?? $pack?->order?->product;
    $status = $booking->status;
    $canAct = $state === CheckInBooking::OK;
@endphp

<x-layouts.app title="Check-in {{ $booking->ticket_code }}" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-8 pb-section" aria-labelledby="checkin-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Gate check-in · {{ $booking->ticket_code }}</p>
        <h1 id="checkin-title" class="mt-2 text-display-lg">{{ $booking->rider->name }}</h1>

        <div class="mt-6 flex flex-col gap-5">
            @if (session('checkin_done'))
                <x-alert variant="success" title="{{ session('checkin_done') }}" />
            @endif
            @if (session('checkin_error'))
                <x-alert variant="error" title="Not recorded">{{ session('checkin_error') }}</x-alert>
            @endif

            @if ($state === CheckInBooking::CANCELLED)
                <x-alert variant="error" title="Cancelled ticket">This booking was cancelled. Don't let the rider in on this ticket.</x-alert>
            @elseif ($state === CheckInBooking::WRONG_DAY)
                <x-alert variant="warning" title="Wrong date">
                    This ticket is for <strong>{{ $slot->starts_at->setTimezone($tz)->format('l j M Y') }}</strong>, not today.
                </x-alert>
            @elseif ($state === CheckInBooking::TOO_EARLY)
                <x-alert variant="warning" title="Too early">Check-in opens at {{ $opensAt->setTimezone($tz)->format('g:i a') }}.</x-alert>
            @elseif ($state === CheckInBooking::ENDED)
                <x-alert variant="warning" title="Session ended">This session finished at {{ $slot->ends_at->setTimezone($tz)->format('g:i a') }}. An admin can still correct attendance in the admin panel.</x-alert>
            @endif

            @if ($status === BookingStatus::Attended && ! session('checkin_done'))
                <x-alert variant="warning" title="Already checked in">
                    {{ $lastChange ? 'At '.$lastChange->created_at->setTimezone($tz)->format('g:i a').($lastChange->causer ? ' by '.$lastChange->causer->name : '').'.' : '' }}
                    Don't check this ticket in twice.
                </x-alert>
            @elseif ($status === BookingStatus::NoShow && ! session('checkin_done'))
                <x-alert variant="info" title="Marked as no-show">If the rider has arrived late, check them in below.</x-alert>
            @endif

            <dl class="grid grid-cols-2 gap-4 rounded-sm border border-line-strong bg-earth-2 p-5">
                <div class="col-span-2">
                    <dt class="text-sm text-sand-dim">Session (Pakistan time)</dt>
                    <dd class="text-lg font-semibold">{{ $slot->label() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">Product</dt>
                    <dd class="font-semibold">{{ $product?->name ?? 'Manual booking' }}{{ $booking->is_private ? ' (private)' : '' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">Status</dt>
                    <dd><x-badge :variant="$status === BookingStatus::Confirmed ? 'neutral' : ($status === BookingStatus::Attended ? 'success' : 'neutral')">{{ $status->label() }}</x-badge></dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-sm text-sand-dim">Paid with</dt>
                    <dd class="font-semibold">
                        @if ($pack)
                            Package: {{ $pack->creditsRemaining() }} {{ $pack->creditsRemaining() === 1 ? 'credit' : 'credits' }} left, valid until {{ $pack->expires_at->setTimezone($tz)->format('j M Y') }} ({{ $pack->status->getLabel() }})
                        @elseif ($booking->order)
                            Order {{ $booking->order->reference }} ({{ $booking->order->status->label() }})
                        @else
                            No charge (admin booking)
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($canAct && $status !== BookingStatus::Attended)
                <form method="POST" action="{{ route('checkin.store', $booking->ticket_code) }}">
                    @csrf
                    <input type="hidden" name="status" value="{{ BookingStatus::Attended->value }}">
                    <button type="submit" class="min-h-16 w-full rounded-sm border border-success bg-success px-6 text-display-xs font-black text-earth focus-visible:outline-offset-4">
                        Check in
                    </button>
                </form>
            @endif

            @if ($canNoShow && $status === BookingStatus::Confirmed)
                <form method="POST" action="{{ route('checkin.store', $booking->ticket_code) }}">
                    @csrf
                    <input type="hidden" name="status" value="{{ BookingStatus::NoShow->value }}">
                    <x-button variant="ghost" type="submit" class="w-full">Mark as no-show</x-button>
                </form>
            @endif

            @if (! $canAct && $status !== BookingStatus::Attended)
                <p class="text-sm text-sand-dim" role="note">Check-in isn't available for this ticket right now.</p>
            @endif
        </div>
    </section>
</x-layouts.app>
