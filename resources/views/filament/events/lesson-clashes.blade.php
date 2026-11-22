{{-- Lesson bookings on days an event closed (decided 2026-11-22): never cancelled automatically. --}}
@if (count($clashes) > 0)
    <x-filament::section heading="Lesson bookings to move" icon="heroicon-o-exclamation-triangle" icon-color="warning">
        <p class="text-sm">These lessons were already booked when this event closed their slots. They have <strong>not</strong> been cancelled: move them, or agree something else with the customer.</p>
        <ul class="mt-3 list-disc space-y-1 ps-5 text-sm">
            @foreach ($clashes as $booking)
                <li>
                    {{ $booking->slot->label() }}: {{ $booking->rider->name ?? 'Rider' }}
                    ({{ $booking->user->email ?? 'no email' }}), ticket {{ $booking->ticket_code }}
                    · <a class="underline" href="{{ \App\Filament\Resources\Slots\SlotResource::getUrl('view', ['record' => $booking->slot_id]) }}">open the slot</a>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
@endif
