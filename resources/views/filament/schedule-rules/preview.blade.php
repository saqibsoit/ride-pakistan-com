{{--
    Rule preview in the schedule rule form (App\Actions\Slots\PreviewScheduleRule).
    Plain semantic markup: Filament's CSS has no utility classes.
--}}
@php
    $show = 12;
    $groups = $preview === null ? [] : [
        ['New slots', $preview->new, 'Will be created and open for booking.'],
        ['Changed slots', $preview->changed, 'Empty open slots that get the new end time or capacity.'],
        ['Removed slots', $preview->removed, 'Empty open slots that will be deleted.'],
        ['Kept as they are', $preview->kept, 'These won’t follow the change. Move or cancel them yourself if needed.'],
    ];
@endphp

<div class="fi-sc-text" aria-live="polite">
    @if ($preview === null)
        <p>Fill in the day, start, end and riders to see the slots this rule creates.</p>
    @elseif ($preview->isEmpty())
        <p>No change to the slots in the next {{ $preview->weeks }} weeks.</p>
    @else
        <p>Next {{ $preview->weeks }} weeks, Pakistan time.</p>
        @foreach ($groups as [$title, $items, $help])
            @if ($items !== [])
                <h3><strong>{{ $title }}: {{ count($items) }}</strong></h3>
                <p>{{ $help }}</p>
                <ul>
                    @foreach (array_slice($items, 0, $show) as $item)
                        <li>
                            {{ $item->label() }}, {{ $item->capacity }} {{ $item->capacity === 1 ? 'rider' : 'riders' }}
                            @if ($item->note) ({{ $item->note }}{{ $item->seatsBooked > 0 ? ', '.$item->seatsBooked.' booked' : '' }})@endif
                        </li>
                    @endforeach
                    @if (count($items) > $show)
                        <li>and {{ count($items) - $show }} more</li>
                    @endif
                </ul>
            @endif
        @endforeach
    @endif
</div>
