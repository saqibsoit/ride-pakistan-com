<x-mail::message>
# Failed admin sign-ins

@if ($own)
Someone has tried and failed to sign in to the Ride Pakistan admin panel with your account {{ $attempts }} times in the last {{ $windowMinutes }} minutes.
@else
Someone has tried and failed to sign in to the Ride Pakistan admin panel as **{{ $account->name ?? $account->email }}** {{ $attempts }} times in the last {{ $windowMinutes }} minutes.
@endif

<x-mail::table>
| | |
|:--|:--|
| Account | {{ $account->email }} |
| Latest attempt | {{ $at->format('j M Y, H:i') }} (Pakistan time) |
| IP address | {{ $ip ?? 'Unknown' }} |
| Browser | {{ $userAgent ?? 'Unknown' }} |
</x-mail::table>

@if ($own)
If this was you, there is nothing to do. If not, change your password now and tell an admin. Your authenticator code still protects the account.
@else
If this wasn't the account holder, check the audit log, consider resetting the account's password, and follow the incident steps in docs/SECURITY.md.
@endif

Ride Pakistan
</x-mail::message>
