{{-- /account/tickets/{code}: printable ticket. The QR code opens the staff check-in page for this ticket. --}}
@php
    $product = $booking->order?->product ?? $booking->creditPack?->order?->product;
    $active = $booking->status === \App\Enums\BookingStatus::Confirmed;
@endphp

<x-layouts.account title="Ticket">
    <article aria-labelledby="ticket-code" class="flex max-w-narrow flex-col gap-6 rounded-sm border border-line-strong bg-earth-2 p-6 md:p-8 print:border-0 print:bg-transparent print:p-0">
        <div class="flex flex-col items-start gap-6 sm:flex-row sm:items-center">
            @if ($active)
                {{-- White tile so the code scans on screen and prints black on white. --}}
                <div class="w-48 shrink-0 rounded-sm bg-sand p-3 text-earth print:w-56" role="img" aria-label="QR code for ticket {{ $booking->ticket_code }}">
                    {!! \App\Support\TicketQr::svg($booking) !!}
                </div>
            @endif
            <div class="flex flex-col gap-1">
                <p class="text-xs uppercase tracking-label text-sand-dim">Ticket code</p>
                <p id="ticket-code" class="font-display text-display-md tracking-label text-sand">{{ $booking->ticket_code }}</p>
                <p class="text-sm text-sand-dim">{{ $active ? 'Show this at the gate. Staff scan the code to check you in.' : 'This ticket is no longer valid.' }}</p>
            </div>
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
            @if ($product)
                <div>
                    <dt class="text-sm text-sand-dim">Session</dt>
                    <dd class="font-semibold text-sand">{{ $product->name }}{{ $booking->is_private ? ' (private)' : '' }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-sm text-sand-dim">Status</dt>
                <dd><x-badge :variant="$active ? 'success' : 'neutral'">{{ $booking->status->label() }}</x-badge></dd>
            </div>
            @if ($booking->order)
                <div>
                    <dt class="text-sm text-sand-dim">Order</dt>
                    <dd><a href="{{ route('account.orders.show', $booking->order->reference) }}" class="underline underline-offset-4 hover:text-sand">{{ $booking->order->reference }}</a></dd>
                </div>
            @endif
        </dl>

        <p class="text-sm text-sand-dim print:hidden">Use your browser's print option to print this ticket or save it as a PDF.</p>
    </article>
</x-layouts.account>
