<x-mail::message>
# {{ $heading }}

@if ($resolved)
@if ($isQueue)
Queued jobs are being processed again. The problem started at {{ $since }} (Pakistan time).
@else
No bank transfer has been waiting for review longer than {{ $transferHours }} hours since {{ $since }} (Pakistan time).
@endif
@elseif ($isQueue)
{{ $count }} background {{ $count === 1 ? 'job has' : 'jobs have' }} been waiting more than {{ $queueMinutes }} minutes. The oldest has been waiting since {{ $oldest }} (Pakistan time). Customer emails, image variants and tracking events are delayed until the queue runs again.

The queue is processed by the cron job that runs `schedule:run` every minute. Check in hPanel that the cron job still exists and look at `storage/logs` for errors. `docs/DEPLOY.md` has the steps.
@else
{{ $count }} bank {{ $count === 1 ? 'transfer has' : 'transfers have' }} been waiting for review more than {{ $transferHours }} hours. The oldest was uploaded at {{ $oldest }} (Pakistan time). Uploaded transfers never expire, so these customers are waiting on us.

<x-mail::button :url="$ordersUrl">
Verify booking transfers
</x-mail::button>

[Verify gift voucher transfers]({{ $vouchersUrl }})
@endif

@unless ($resolved)
You get this email when the problem starts, every {{ $repeatHours }} hours while it lasts, and once when it is fixed.
@endunless

Ride Pakistan
</x-mail::message>
