{{-- /account/tickets/{code}. The QR code arrives with check-in (milestone 8). --}}
<x-layouts.account title="Ticket">
    <article aria-labelledby="ticket-code" class="flex max-w-narrow flex-col gap-6 rounded-sm border border-line-strong bg-earth-2 p-6 md:p-8">
        <div class="flex flex-col gap-1">
            <p class="text-xs uppercase tracking-label text-sand-dim">Ticket code</p>
            <p id="ticket-code" class="font-display text-display-md tracking-label text-sand">{{ $booking->ticket_code }}</p>
        </div>

        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-sand-dim">When (Pakistan time)</dt>
                <dd class="font-semibold text-sand"><time datetime="{{ $booking->slot->starts_at->toIso8601String() }}">{{ $booking->slot->label() }}</time></dd>
            </div>
            <div>
                <dt class="text-sm text-sand-dim">Rider</dt>
                <dd class="font-semibold text-sand">{{ $booking->rider->name }}</dd>
            </div>
            <div>
                <dt class="text-sm text-sand-dim">Status</dt>
                <dd><x-badge :variant="$booking->status === \App\Enums\BookingStatus::Confirmed ? 'success' : 'neutral'">{{ $booking->status->label() }}</x-badge></dd>
            </div>
            @if ($booking->order)
                <div>
                    <dt class="text-sm text-sand-dim">Order</dt>
                    <dd><a href="{{ route('account.orders.show', $booking->order->reference) }}" class="underline underline-offset-4 hover:text-sand">{{ $booking->order->reference }}</a></dd>
                </div>
            @endif
        </dl>
    </article>
</x-layouts.account>
