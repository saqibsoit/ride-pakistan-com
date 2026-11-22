{{--
    An event booking in an email (decided 2026-11-22): date, people,
    payment, dress code, what to bring and the cancellation rules.
    Pass `event` (App\Support\Events\EventBooking).
--}}
**{{ $event->event->title }}**<br>
{{ $event->dateLabel() }} (Pakistan time){{ $event->event->location_name ? ', '.$event->event->location_name : '' }}

@if ($event->people() !== [])
@foreach ($event->people() as $person)
- {{ $person['name'] }}{{ $person['tier'] !== '' && $person['tier'] !== 'Per person' ? ' ('.$person['tier'].')' : '' }}
@endforeach

@endif
@if ($event->order->deposit_percent !== null)
**Deposit paid:** {{ \App\Support\Money::format($event->paidNowMinor(), $event->order->currency) }} (non-refundable)<br>
@endif
@if ($event->balanceLine() !== '')
**{{ $event->balanceLine() }}**
@endif

@if ($event->dressCode() !== [])
## Dress code
@foreach ($event->dressCode() as $line)
- {{ $line }}
@endforeach
@if ($event->acknowledgement())

You agreed: “{{ $event->acknowledgement() }}”
@endif

@endif
@if ($event->whatToBring() !== [])
## What to bring
@foreach ($event->whatToBring() as $item)
- {{ $item }}
@endforeach

@endif
## Cancellations
@foreach ($event->terms() as $line)
- {{ $line }}
@endforeach

