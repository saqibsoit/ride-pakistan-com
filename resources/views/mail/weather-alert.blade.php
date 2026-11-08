<x-mail::message>
# {{ $heading }}

**{{ $slot->label() }}** (Pakistan time)

@if ($severity === \App\Enums\WeatherSeverity::Clear)
The forecast for this session no longer crosses any alert threshold{{ $previous ? ' (it was '.strtolower($previous->label()).')' : '' }}.
@else
Level: **{{ $severity->label() }}**{{ $previous && $previous !== \App\Enums\WeatherSeverity::Clear ? ' (was '.strtolower($previous->label()).')' : '' }}

@foreach ($crossed as $condition)
- {{ $condition['text'] }} at {{ $condition['at'] }}
@endforeach
@endif

Riders booked: **{{ $riders }} of {{ $slot->capacity }}**

@if ($hours !== [])
<x-mail::table>
| Time | Rain chance | Rain | Gusts | Temp. | Code |
|:--|:--|:--|:--|:--|:--|
@foreach ($hours as $hour)
| {{ $hour['at'] }} | {{ $hour['rain'] }} | {{ $hour['mm'] }} | {{ $hour['gusts'] }} | {{ $hour['temp'] }} | {{ $hour['code'] }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="$runSheetUrl" color="primary">
Open the run sheet
</x-mail::button>

@if ($adminUrl)
<x-mail::button :url="$adminUrl">
Cancel, move or notify riders in admin
</x-mail::button>
@endif

Forecast from Open-Meteo. Check again before deciding: forecasts change.

Ride Pakistan
</x-mail::message>
