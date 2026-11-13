<x-mail::message>
# Your credits expire soon

You have **{{ $left }} {{ $left === 1 ? 'credit' : 'credits' }}** left on your package. {{ $left === 1 ? 'It expires' : 'They expire' }} on **{{ $pack->expires_at->setTimezone($timezone)->format('l j M Y') }}**, and expired credits can't be used.

Book your next {{ $left === 1 ? 'session' : 'sessions' }} before then: any of your riders can use them.

<x-mail::button :url="\App\Support\ManageLink::forPackBooking($pack)" color="primary">
Book with your credits
</x-mail::button>

No sign-in needed: the button opens your package. Anyone with the link can use it, so please don't share it.

Ride Pakistan
</x-mail::message>
