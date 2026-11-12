<x-mail::message>
# Your request wasn't sent

Someone asked for a private session on **{{ $when }}** (Pakistan time) with this email address. You already have a private session request waiting, and only one can be open at a time, so we didn't send this one.

To change the time, sign in and withdraw the waiting request, then ask again.

<x-mail::button :url="route('account.private-requests')">
See your requests
</x-mail::button>

If this wasn't you, you can ignore this email. Nothing was booked.

Ride Pakistan
</x-mail::message>
