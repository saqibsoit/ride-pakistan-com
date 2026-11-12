{{--
    "Manage your booking" (guest checkout): the signed link to this booking's
    order, no sign-in needed. Pass `for`: an order, a booking, a package or
    a list of bookings (App\Support\ManageLink::linksFor). Nothing shows for
    a booking without an order (a no-charge booking added by us).
--}}
@php($manageLinks = \App\Support\ManageLink::linksFor($for ?? null))
@if (count($manageLinks) === 1)
<x-mail::button :url="reset($manageLinks)" color="primary">
Manage your booking
</x-mail::button>
@elseif (count($manageLinks) > 1)
**Manage your bookings:** @foreach ($manageLinks as $reference => $url)[{{ $reference }}]({{ $url }})@if (! $loop->last) · @endif @endforeach

@endif
@if ($manageLinks !== [])
See your tickets{{ ($payable ?? false) ? ', pay or upload your transfer' : '' }}, and move or cancel within our rules, without signing in. Anyone with the link can open the booking, so please don't share it. It works until 7 days after your last session.

@endif
