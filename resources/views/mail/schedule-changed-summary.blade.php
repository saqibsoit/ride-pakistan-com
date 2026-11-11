<x-mail::message>
# Your sessions changed

The schedule was just changed. These are your sessions (Pakistan time):

@foreach ($lines as $line)
- {{ $line }}
@endforeach

Customers whose session moved have been emailed and can keep the new time, move or cancel. The run sheets always show the latest riders.

<x-mail::button :url="$runSheets" color="primary">
Open your run sheets
</x-mail::button>

Ride Pakistan
</x-mail::message>
