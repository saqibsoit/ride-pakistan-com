<x-mail::message>
# Weather forecasts are failing

The weather forecast hasn't loaded since **{{ $since }}** (Pakistan time). The site keeps trying every hour.

@if ($lastSuccess)
Last good forecast: {{ $lastSuccess }}. Weather badges and alerts are based on it until a new one loads, so they may be out of date.
@else
No forecast has loaded yet, so there are no weather badges or alerts.
@endif

@if ($error !== '')
Last error: {{ $error }}
@endif

Until it works again, check the weather yourself before weekend sessions. You get this email once per outage.

Ride Pakistan
</x-mail::message>
