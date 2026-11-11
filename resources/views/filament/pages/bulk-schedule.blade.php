{{--
    Bulk schedule editor (App\Filament\Pages\BulkSchedule, admins, prompt 15A).
    Plain semantic markup: Filament's CSS has no utility classes.
--}}
@php
    $preview = $this->previewObject();
    $trainers = $this->trainerNames();
    $trainer = fn (?int $id): string => $id === null ? 'nobody' : ($trainers[$id] ?? 'trainer #'.$id);
    $groups = $preview === null ? [] : [
        ['create', 'New slots', 'Will be created and open for booking.'],
        ['update', 'Changed slots', 'Customers in slots that move get one email each to keep the new time, move or cancel.'],
        ['close', 'Closed slots', 'Bookings stay; nobody new can book.'],
        ['cancel', 'Cancelled slots', 'Bookings are cancelled through the usual slot cancellation: credits back, paid bookings flagged for refund, one email per customer.'],
        ['skip', 'Skipped', 'These won’t change.'],
    ];
@endphp
<x-filament-panels::page>
    <form wire:submit="preview" class="fi-sc-form">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-o-eye">Preview</x-filament::button>
        </div>
    </form>

    @if ($summary !== null)
        <x-filament::section heading="Done" aria-live="polite">
            <ul>
                <li>Slots created: <strong>{{ $summary['created'] }}</strong></li>
                <li>Slots changed: <strong>{{ $summary['updated'] }}</strong></li>
                <li>Slots closed: <strong>{{ $summary['closed'] }}</strong></li>
                <li>Slots cancelled: <strong>{{ $summary['cancelled'] }}</strong></li>
                <li>Customers emailed: <strong>{{ $summary['customers_notified'] }}</strong></li>
                <li>Trainers emailed: <strong>{{ $summary['trainers_notified'] }}</strong></li>
            </ul>
            @if ($summary['failures'] !== [])
                <h3><strong>Not applied: {{ count($summary['failures']) }}</strong></h3>
                <ul>
                    @foreach ($summary['failures'] as $failure)
                        <li>{{ $failure }}</li>
                    @endforeach
                </ul>
            @else
                <p>Everything in the preview was applied.</p>
            @endif
        </x-filament::section>
    @endif

    @if ($preview !== null)
        <x-filament::section heading="Preview (nothing is saved yet)" aria-live="polite">
            @if ($preview->items === [])
                <p>No slots match. Check the dates, days and start time.</p>
            @else
                <p>Pakistan time. Booked = confirmed riders; held = seats in unpaid orders or waitlist offers.</p>
                @foreach ($groups as [$kind, $title, $help])
                    @php($items = array_values(array_filter($preview->items, fn ($i) => $i->kind === $kind)))
                    @if ($items !== [])
                        <h3><strong>{{ $title }}: {{ count($items) }}</strong></h3>
                        <p>{{ $help }}</p>
                        <ul>
                            @foreach ($items as $item)
                                <li>
                                    {{ $item->label() }}
                                    @if ($item->newLabel()) → <strong>{{ $item->newLabel() }}</strong>@endif
                                    · {{ $item->newCapacity !== null ? $item->capacity.' → '.$item->newCapacity : $item->capacity }} riders
                                    @if ($item->newTrainerId !== null) · trainer {{ $kind === 'create' ? $trainer($item->newTrainerId) : $trainer($item->trainerId).' → '.$trainer($item->newTrainerId) }}@endif
                                    @if ($kind !== 'create') · {{ $item->seatsBooked }} booked{{ $item->seatsHeld > 0 ? ', '.$item->seatsHeld.' held' : '' }}@endif
                                    @if ($item->note) ({{ $item->note }})@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach

                <div>{{ $this->applyAction }}</div>
            @endif
        </x-filament::section>
    @endif

</x-filament-panels::page>
