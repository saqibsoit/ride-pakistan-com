<x-mail::message>
# Your sign-in code

Enter this code to sign in to Ride Pakistan:

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

It works once and expires in {{ $minutes }} minutes.

If you didn’t ask for this code, you can ignore this email. Nobody can sign in without it.

Ride Pakistan
</x-mail::message>
